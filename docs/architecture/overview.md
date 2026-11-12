# CloudForge architecture

CloudForge is a self-hosted platform-as-a-service control plane. It owns the
organizations, projects, servers and audit history, while a small Go agent runs
on each customer VPS and performs the infrastructure work that the control plane
requests.

This document describes the Phase 1 foundation. Deployment pipelines, build
workers, databases and routing are later phases and are not present in the
codebase.

## System context

```text
                   HTTPS (session cookie)
  Browser / React  ───────────────────────┐
  Inertia SPA                             │
                                          ▼
                                ┌────────────────────┐
                                │ Laravel control    │
                                │ plane              │
                                │                    │
  GitHub Actions ── artifacts ─▶│  PostgreSQL        │
                                │  Redis             │
                                │  local storage     │
                                └─────────┬──────────┘
                                          │ HTTPS + RSA request signatures
                                          │ client certificate (mTLS at edge)
                                          ▼
                                ┌────────────────────┐
                                │ Go agent           │
                                │ systemd service    │
                                └─────────┬──────────┘
                                          │ read-only Unix socket
                                          ▼
                                ┌────────────────────┐
                                │ Docker Engine      │
                                └────────────────────┘
```

The agent only makes outbound connections. It never listens on a port, and the
Docker socket is never exposed to the control plane or the browser.

## Control plane

The control plane is a Laravel 13 application in `apps/control-plane`. It serves
an Inertia v3 + React 19 single-page application and a JSON API from the same
codebase.

Domain code lives under `app/Domains`:

| Domain | Responsibility |
| --- | --- |
| `Identity` | Users, password authentication and sessions |
| `Organizations` | Organizations, memberships, invitations, roles and API tokens |
| `Projects` | Projects and their environments |
| `Infrastructure` | Servers, certificates, enrollment, heartbeats, inventory and metrics |
| `Operations` | Audit log and the durable agent-command queue |

Controllers stay thin. Multi-step work is implemented in actions and services,
for example `RedeemEnrollmentToken`, `RecordHeartbeat` and `AgentCommandService`.

### Persistence

Phase 1 uses PostgreSQL in production and SQLite in the test suite. Redis is the
production session, cache and queue store. All externally visible records use
UUID primary keys so identifiers do not leak ordering or volume.

| Table | Purpose |
| --- | --- |
| `users` | Human accounts |
| `organizations` | Tenant boundary |
| `organization_members` | User-to-organization membership and role |
| `organization_invitations` | Pending, hashed-token invitations |
| `organization_api_tokens` | Hashed organization-scoped API tokens |
| `projects` | Application projects |
| `environments` | Development, staging and production environments |
| `servers` | Registered VPS nodes, including unclaimed bootstrap nodes (`machine_id`, `is_bootstrap`, `claimed_at`) |
| `server_roles` | Intended role of a server (application, database, …) |
| `server_capabilities` | Inspected runtime capabilities reported by the agent |
| `server_certificates` | Issued agent certificates and revocation state |
| `server_enrollments` | Hashed, single-use, short-lived enrollment tokens and their purpose |
| `server_metrics` | Host metric samples with retention pruning |
| `agent_commands` | Durable command queue and command outcomes |
| `audit_logs` | Organization-scoped security and lifecycle history |

### Web surface

The dashboard provides authentication, onboarding, organization settings,
member invitations and role management, projects and environments, servers,
API tokens and the audit log. Onboarding also lists unclaimed bootstrap nodes;
creating an organization claims them. Authorization is enforced
server-side with policies and organization role checks; the React layer only
hides actions the user cannot perform.

### API surface

Two APIs share the `/api` prefix:

- `/api/v1/*` is the organization API. It uses a Bearer token prefixed with
  `cf_`, stores only the SHA-256 hash of the token, and supports explicit
  abilities such as `servers:read` and `commands:write`.
- `/api/agent/v1/*` is the agent protocol. It uses the agent's RSA key and an
  issued client certificate; enrollment is the only unauthenticated endpoint.

See [API reference](../api/README.md) and [agent protocol](../agent/protocol.md).

## Go agent

The agent lives in `apps/agent` and uses only the Go standard library.

```text
cmd/cloudforge-agent     CLI entry point (enroll, run, version)
internal/config          environment and flag resolution
internal/identity        RSA keys, CSRs, certificate parsing, fingerprints
internal/enrollment      one-time token redemption and identity persistence
internal/client          signed HTTPS transport
internal/agent           heartbeat, inventory, metrics, command and rotation loops
internal/commands        allowlisted executor and durable deduplication store
internal/docker          read-only Docker Engine inspection over a Unix socket
internal/metrics         Linux host sampling
internal/protocol        JSON wire types shared by every request
```

The agent is a systemd service. It keeps its private key in
`/var/lib/cloudforge-agent/agent.key` with mode `0600` and its configuration in
`/etc/cloudforge/agent.env` with mode `0600`. An operator-supplied enrollment
token is passed through the installer environment and is never written to
`agent.env`. In the self-hosted bootstrap flow the token is held in a separate
`0600` credential file that the installer deletes after enrollment; it is never
written to `agent.env` either.

## Key flows

### Enrollment

1. An owner or administrator registers a server and requests an enrollment
   token in the dashboard or API.
2. The control plane generates a random token, stores only its SHA-256 hash,
   and returns the plaintext token once with a 15-minute expiry and a generated
   `install.sh` command.
3. The agent generates an RSA-2048 key and a CSR whose common name is the
   server UUID.
4. `POST /api/agent/v1/enroll` atomically redeems the token, validates the CSR
   subject and public key, and signs the certificate with the control-plane
   agent CA.
5. The agent verifies the issued certificate against the returned CA and the
   expected server ID, then writes the identity atomically and discards the
   token.
6. The server transitions from `installation_pending` to `agent_registered`.

Enrollment tokens are single-use. A replay, expiry or revoked token returns
`422` with `code: "enrollment_failed"`.

### Self-hosted bootstrap

The first control-plane host is also a managed server. The bootstrap flow avoids
a manual copy of a server UUID and token from the dashboard:

1. `php artisan cloudforge:bootstrap` inspects the local host, reads
   `/etc/machine-id`, and creates an unclaimed server record with
   `organization_id = NULL`, `is_bootstrap = true` and the host's `machine_id`.
   Re-running the command finds the record by machine-id instead of creating a
   duplicate.
2. The command issues a bootstrap enrollment token scoped to that server and
   writes a `0600` shell credential plus a `0600` status document. The token has
   a 30-minute default TTL and is never printed or stored in the status file.
3. The installer sources the credential, enrolls the agent, and deletes the
   credential. The agent reports the same `machine_id`; a mismatch is rejected
   and the token is not consumed.
4. A bootstrap server is claimed by creating an organization, by enrolling
   while exactly one organization exists, or explicitly with
   `php artisan cloudforge:bootstrap:claim`.

Claiming an unclaimed bootstrap server emits `server.bootstrap_claimed`.
Provisioning emits `server.bootstrap_provisioned` with a hashed machine-id, never
the raw value. Installation tokens generated from the dashboard are bound to a
server UUID but not to a machine-id; bootstrap tokens are bound to both.

### Authenticated requests

Every non-enrollment agent request carries four headers:

```text
X-CloudForge-Fingerprint  lowercase SHA-256 fingerprint of the client certificate
X-CloudForge-Timestamp    Unix seconds
X-CloudForge-Nonce        base64url nonce, 16–128 characters
X-CloudForge-Signature    base64 RSA-SHA256 signature
```

The signature covers:

```text
METHOD \n request-uri \n timestamp \n nonce \n sha256(raw-body)
```

The control plane resolves the certificate by fingerprint, checks that it has
not been revoked, enforces a 300-second clock skew, caches nonces for twice the
skew window to reject replay, and verifies the signature against the public key
stored on first enrollment.

TLS is mandatory for production (`https://` only, except loopback development).
The trusted TLS terminator can forward the presented client certificate serial
or fingerprint; enabling `CLOUDFORGE_REQUIRE_CLIENT_CERT_HEADER=true` makes the
application reject requests that do not match the registered identity.

### Heartbeats, inventory and metrics

- Heartbeat every 30 seconds by default. The control plane records the
  heartbeat, updates `last_heartbeat_at`, sets the server reachable and emits
  `server.heartbeat` only on a state change.
- Inventory every 10 minutes and on demand. Host facts and Docker facts derived
  from `GET /version` and `GET /info` are stored on the server and in
  `server_capabilities`. The agent does not enumerate containers, images,
  networks or volumes in Phase 1.
- Metrics every 30 seconds. Samples are buffered in the agent if a request
  fails and are pruned after 14 days by `metrics:prune`.
- `servers:mark-unreachable` runs every minute. A server whose last heartbeat
  is older than 90 seconds becomes `unreachable`; the next heartbeat emits
  `server.recovered`.

### Commands

Commands are persisted before they are delivered. The lifecycle is:

```text
pending → acknowledged → completed | failed
        ↘ cancelled
        ↘ expired
```

The agent long-polls `GET /api/agent/v1/commands/next`. The control plane
redelivers acknowledged commands after one minute, only while the command has
not expired. There is no fixed attempt cap; `attempts` counts deliveries. The
agent stores each terminal outcome in `commands.json` and replays the stored
result instead of executing the operation again. If a previous run stopped while
a command was marked `executing`, the agent reports it as failed rather than
repeating an operation whose side effects are unknown.

Phase 1 allows four operations:

| Operation | Effect | Role |
| --- | --- | --- |
| `server.inspect` | Read host and Docker inventory | Operator or developer |
| `server.health` | Read agent and Docker health | Operator or developer |
| `metrics.collect` | Read one metric sample | Operator or developer |
| `agent.restart` | Report the result, then exit for systemd to restart | Operator or above |

There is no generic shell operation and no Docker mutation endpoint.

## Trust boundaries

| Boundary | Control |
| --- | --- |
| Browser → control plane | Session cookie, CSRF, rate limiting, organization-scoped policies |
| API client → control plane | Hashed Bearer token with explicit abilities |
| Agent → control plane | Client certificate plus per-request RSA signature, replay protection, revocation |
| Control plane → agent | No inbound connection; commands are pulled by the agent |
| Agent → Docker | Local Unix socket, read-only inspection calls only |
| Operator → host | SSH and the operating system, outside CloudForge |

Tenant isolation is enforced at the query level: every web and API lookup is
rooted at the active organization. Cross-organization resources return `403` on
the web surface and `404` on the API surface so the API does not confirm the
existence of another tenant's records.

## Deployment topology

A minimal production installation is one control-plane host and one or more VPS
nodes:

- The control-plane host runs PHP-FPM, a web server, PostgreSQL, Redis, the
  queue worker and the scheduler.
- The agent CA is stored on the control-plane persistent volume under
  `storage/app/private/agent-ca` (`ca.key.pem`, `ca.crt.pem`, `openssl.cnf`).
  It is created on first use and must be backed up with the database.
- Each VPS runs Docker Engine and the `cloudforge-agent` systemd service.
- A TLS terminator (the web server or a load balancer) serves the public HTTPS
  endpoint and may optionally verify agent client certificates.

The full procedure is in [control-plane deployment](../deployment/control-plane.md).

## Explicit Phase 1 limits

- No deployment, build, database, routing or scaling engines.
- No arbitrary command execution and no Docker write operations.
- Commands are pulled; there is no server-initiated connection to an agent.
- Certificate revocation is enforced on every request, but there is no CRL or
  OCSP responder because the control plane is the only verifier.
- The agent CA private key is a single point of trust and must be protected and
  backed up like a root credential.

## Decisions

- [ADR 0001: control-plane stack](adr/0001-control-plane-stack.md)
- [ADR 0002: agent identity and mTLS](adr/0002-agent-identity-and-mtls.md)
- [ADR 0003: durable command model](adr/0003-durable-command-model.md)
- [ADR 0004: organization-scoped authorization](adr/0004-organization-scoped-authorization.md)
