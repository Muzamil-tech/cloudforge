# ADR 0003: Durable command model

- Status: Accepted
- Date: 2026-11-12
- Phase: 1

## Context

The control plane must ask an agent to perform an infrastructure operation, but
the agent is behind NAT, may be offline, and may restart mid-operation. The
system cannot rely on a synchronous request to the agent, and it must not repeat
an operation whose side effects are unknown.

Phase 1 also forbids arbitrary shell execution.

## Decision

### Persist before delivery

Every command is written to `agent_commands` with a UUID `command_id`, an
allowlisted operation, a JSON payload, an expiry time and a status. The response
to the operator is `202 Accepted`; execution happens asynchronously when the
agent polls.

### Pull-based delivery

The agent calls `GET /api/agent/v1/commands/next`, optionally long-polling up to
25 seconds. The control plane returns one command at a time and marks it
`acknowledged` when the agent confirms receipt. The agent then executes the
operation and posts the terminal result.

### Allowlist

The operation is an enum fixed at compile time in both services. Phase 1 has
four operations: `server.inspect`, `server.health`, `metrics.collect` and
`agent.restart`. Unknown operations are rejected when issued and are reported as
failed if they somehow reach an agent. No endpoint accepts a shell command,
script, executable path or Docker API payload.

### Deduplication and recovery

The agent persists every terminal outcome in `commands.json`. If the same
command is redelivered, the agent replays the stored result instead of executing
the operation again. Before executing, it writes an `executing` marker. If the
process stops while that marker is present, the next delivery reports the
command as failed with an explicit "not repeated" message. This favors a
conservative failure over an unknown duplicate side effect.

Acknowledged commands are redelivered after one minute, only while the command
has not expired. `attempts` counts deliveries; there is no separate fixed
attempt cap. Pending and acknowledged commands expire after their TTL.
Cancelling a terminal command is a no-op.

### Idempotency at the edge

Callers may supply an `Idempotency-Key` header or `idempotency_key` field. The
control plane uses it as the command's idempotency key so a retried API call
does not create a second command.

## Consequences

Positive:

- Offline agents receive work when they reconnect.
- Command history is auditable and visible in the dashboard and API.
- A crash cannot silently repeat a non-idempotent operation.
- The allowlist makes the attack surface reviewable.

Negative:

- Command completion is eventually consistent, not synchronous.
- The agent’s local `commands.json` is durable state that must be writable and
  backed up if exact replay history matters.
- A command that legitimately needs retry after a crash must be issued again by
  an operator with a new command ID.

## Alternatives considered

- **Direct HTTP call to the agent.** Rejected: requires inbound connectivity and
  fails when the node is offline.
- **Queue job that calls the agent.** Still requires inbound connectivity and
  loses the durable command record if the job is discarded.
- **Message broker per agent.** Operationally heavier for Phase 1 and adds a
  dependency the agent would need to speak. Long-polling over HTTPS is enough
  for the expected command volume.
- **At-least-once execution without a dedupe store.** Rejected because a
  restarted agent could repeat an operation with unknown side effects.
