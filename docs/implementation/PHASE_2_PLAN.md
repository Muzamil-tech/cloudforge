# Phase 2 plan — application deployments

**Status:** Draft for implementation
**Version:** 1.3.0 target
**First slice:** prebuilt Docker image deployments
**Owner:** CloudForge
**Last updated:** 2026-11-12

---

## 1. Why this slice

Phase 2 is application deployment. The full milestone includes GitHub App
installation, repository import, framework detection, BuildKit builds, releases,
rollback, environment variables, workers, domains and live logs. Delivering all
of that at once would create a large, unverifiable surface.

This plan starts with the smallest honest deployment path:

> A project member supplies a prebuilt container image. CloudForge pulls it on the
> selected server, starts it as a managed application container, health-checks it,
> records the deployment and its real logs, and supports redeploy, rollback,
> start, stop, refresh and delete.

This gives a real deployment engine that can be verified on the current VPS
before GitHub and BuildKit are added. Every state comes from an agent result.
There is no simulated deployment, no fake log and no UI-only status.

### Phase 1 gate

`IMPLEMENTATION_PLAN.md` and `PROGRESS.md` currently say Phase 2 must not start
until the Phase 1 acceptance run is completed. The owner has explicitly directed
Phase 2 to start with this slice.

This plan therefore records an explicit exception:

- Phase 2 **code** may proceed on the owner's direction.
- Phase 1 acceptance remains open and must still be completed.
- Nothing in this slice is marked "verified in real infrastructure" until the
  acceptance run in section 13 is recorded in `PROGRESS.md`.

---

## 2. Scope

### In scope

- Applications belong to one project, environment and server.
- A user deploys a prebuilt image reference such as `nginx:1.27-alpine`.
- The agent pulls the image, creates a new container, publishes its port on
  `127.0.0.1`, starts it and optionally performs an HTTP health check.
- A successful deployment replaces the previous container without stopping it
  until the new container is healthy.
- A failed deployment removes the failed container and leaves the previous
  version running.
- Deployment history, status, timestamps, error and bounded container logs are
  stored and shown.
- Redeploy the current image, roll back to a previous deployment's image, start,
  stop, refresh and delete.
- Reconciliation for expired, missing or completed-without-result commands.

### Out of scope for this slice

- GitHub App installation, repository import and webhooks.
- Framework detection and BuildKit builds.
- Environment variables and secret injection.
- Custom domains, reverse proxy and TLS.
- Live streaming logs and WebSockets.
- Persistent volumes and stateful application containers.
- Workers, schedulers, Horizon, Octane and Reverb.
- Horizontal scaling and multi-server placement.
- MySQL, database attachment and backups.

These remain Phase 2/3 work in `IMPLEMENTATION_PLAN.md`.

---

## 3. Architecture and flow

```text
Browser
  │  create application / deploy / rollback
  ▼
Control plane (Laravel)
  │  transaction: application + deployment + typed command
  ▼
agent_commands (durable, expiring, audited)
  │  agent polls over signed HTTPS
  ▼
CloudForge agent
  │  application.deploy / start / stop / delete / inspect
  ▼
Docker Engine (local Unix socket)
  │  pull image, create new container, start, health check
  ▼
Agent result
  │
  ▼
Control plane applies the real result, deployment record and logs
```

The control plane never talks to Docker. The agent never accepts a shell
command, a Docker API path, a mount, a privileged flag or an arbitrary host
network request.

### Deployment rollout

1. The control plane creates a `deployments` row and issues
   `application.deploy` with a new unique container name.
2. The agent pulls the image.
3. The agent creates a new container. The old container keeps running.
4. Docker assigns a new loopback host port.
5. The agent starts the new container and performs the configured health check.
6. If healthy:
   - the agent reads a bounded log tail;
   - removes the previous application container;
   - reports success with the new container ID, name and port.
7. If unhealthy or the container exits:
   - the agent reads the failed container logs;
   - removes the new container;
   - leaves the previous container untouched;
   - reports failure with the logs.
8. The control plane marks the deployment active or failed. On success it marks
   the previous active deployment superseded.

---

## 4. Data model

### `applications`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | UUID PK | |
| `organization_id` | UUID FK | cascade delete |
| `project_id` | UUID FK | restrict delete while applications exist |
| `environment_id` | UUID FK | restrict |
| `server_id` | UUID FK | restrict |
| `name` | string | display name |
| `slug` | string | unique per environment, generated from name |
| `image` | string | current desired image |
| `container_name` | string | current container; unique |
| `container_id` | string nullable | set after the agent succeeds |
| `container_port` | unsigned small integer | port the app listens on inside the container |
| `host` | string | always `127.0.0.1` in this slice |
| `host_port` | unsigned small integer nullable | Docker-assigned published port |
| `health_path` | string nullable | optional HTTP path such as `/up` |
| `status` | string | see below |
| `last_command_id` | UUID nullable | latest typed command |
| `last_error` | text nullable | real agent/reconciliation error |
| `metadata` | JSON nullable | non-secret deployment metadata |
| `created_at`, `updated_at` | timestamps | |
| `deleted_at` | timestamp nullable | soft delete after container removal |

Indexes:

- `unique(project_id, environment_id, slug)`
- `unique(container_name)`
- `index(organization_id, status)`
- `index(server_id, status)`
- `index(project_id, environment_id)`

### `deployments`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | UUID PK | |
| `organization_id` | UUID FK | cascade |
| `project_id` | UUID FK | restrict |
| `environment_id` | UUID FK | restrict |
| `application_id` | UUID FK | cascade with the application |
| `server_id` | UUID FK | restrict |
| `image` | string | image for this deployment |
| `container_name` | string | new unique container for this deployment |
| `container_id` | string nullable | set on success |
| `host_port` | unsigned small integer nullable | set on success |
| `status` | string | see below |
| `command_id` | UUID nullable | agent command |
| `logs` | text nullable | bounded real container output |
| `error_message` | text nullable | bounded agent/reconciliation error |
| `started_at` | timestamp nullable | when the command was issued |
| `finished_at` | timestamp nullable | when the agent result was applied |
| `metadata` | JSON nullable | previous status, previous container, rollback source |
| `created_at`, `updated_at` | timestamps | |

Indexes:

- `unique(container_name)`
- `index(application_id, created_at)`
- `index(command_id)`
- `index(organization_id, status)`

### `deployment_events`

Real lifecycle timeline, separate from container stdout/stderr.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | UUID PK | |
| `deployment_id` | UUID FK | cascade |
| `level` | string | `info`, `warning`, `error` |
| `message` | text | human-readable event |
| `context` | JSON nullable | command IDs, container IDs, health code |
| `created_at` | timestamp | |

Index: `index(deployment_id, created_at)`.

The control plane records events for: deployment requested, command issued,
agent result applied, health passed, health failed, previous container removed,
rollback requested, reconciliation failure.

### Statuses

`ApplicationStatus`:

- `deploying` — a create or redeploy command is current.
- `active` — the agent reports a running, healthy container.
- `starting` — a start command is current.
- `stopping` — a stop command is current.
- `refreshing` — an inspect command is current.
- `stopped` — the agent reports the container stopped.
- `failed` — provisioning/start failed; inspect may recover.
- `deleting` — a delete command is current.
- `deleted` — container removed; row is soft-deleted.

Transitional statuses: `deploying`, `starting`, `stopping`, `refreshing`,
`deleting`.

`DeploymentStatus`:

- `deploying` — command issued or executing.
- `active` — the agent reported a healthy running container.
- `failed` — the agent reported failure or the command expired.
- `superseded` — a newer deployment became active.
- `cancelled` — the application was deleted before the deployment finished.

Only one deployment per application may be `active` or `deploying` at a time.
The control plane enforces this in a transaction and with row locks.

### What is deliberately **not** stored

- No application secrets or environment variables in this slice.
- No container command, entrypoint override, mount, privileged flag or host
  network option.
- No plaintext registry credentials.
- No fake health or metrics.

---

## 5. Agent contract

### Image policy

- The control plane validates the image reference before issuing a command.
- The agent validates it again. The accepted grammar is a constrained Docker
  reference: registry host (optional), repository path, tag or digest.
- Docker Hub references (no registry host) use the mirror-first order:
  1. `mirror.gcr.io/<normalized-reference>`
  2. the original reference
  3. no ECR fallback for arbitrary images; ECR public only mirrors a subset.
- References with an explicit registry host are pulled exactly as supplied.
- Each registry attempt is bounded to three minutes.
- Local images are reused before any registry request.
- Registry credentials are not supported in this slice.
- Images with a digest are allowed and must be passed through unchanged.

### Operations

All payloads are typed, validated and size-bounded.

#### `application.deploy`

```json
{
  "application_id": "uuid",
  "deployment_id": "uuid",
  "container_name": "cloudforge-app-<uuid>-<short>",
  "previous_container_name": "cloudforge-app-...",
  "image": "nginx:1.27-alpine",
  "container_port": 80,
  "health_path": "/",
  "reason": "Deploy web"
}
```

Rules:

- `application_id` and `deployment_id` must be UUIDs.
- Container names must match the allowlisted CloudForge application name
  pattern.
- `container_port` is 1–65535.
- `health_path` is empty or starts with `/`, max 255 characters.
- The image must pass the reference validator.
- The previous container is only removed after the new container is healthy and
  only if its `cloudforge.application_id` label matches.
- A retry with an existing new container inspects that container, starts it when
  necessary and continues the health check. It never creates a second container
  with the same name.

Result:

```json
{
  "application_id": "uuid",
  "deployment_id": "uuid",
  "container_id": "sha256...",
  "container_name": "cloudforge-app-...",
  "host": "127.0.0.1",
  "port": 32780,
  "status": "running",
  "health": "healthy",
  "previous_container_removed": true,
  "logs": "bounded container stdout/stderr"
}
```

`logs` is also returned on failure, with `status: "failed"`.

#### `application.start`

Payload: `application_id`, `container_name`, `container_id` (optional).

- Verify labels before starting.
- Missing container is an error.
- Already running is success.

#### `application.stop`

Payload: `application_id`, `container_name`, `container_id` (optional).

- Verify labels before stopping.
- Missing container is success (already gone).
- Already stopped is success.

#### `application.delete`

Payload: `application_id`, `container_name`, `container_id` (optional).

- Verify labels.
- Force-remove the container.
- Missing container is success.
- No volume is removed in this slice.

#### `application.inspect`

Payload: `application_id`, `container_name`.

Result: `status` (`running`, `stopped`, `missing`), `container_id`, `port`,
`health`, `image`.

#### `application.logs`

Payload: `application_id`, `container_name`, `tail` (default 200, max 500).

Result: bounded `logs` string.

### Health checks

- If `health_path` is set, the agent polls
  `http://127.0.0.1:<host_port><health_path>` every two seconds for up to 60
  seconds. Any 200–399 response is healthy.
- The agent also fails early if Docker reports the container exited.
- If no path is set, the agent waits for Docker `State.Running`.
- If the image defines a Docker `HEALTHCHECK`, the agent respects
  `State.Health.Status`: `healthy` passes, `unhealthy` fails, `starting` waits up
  to the same 60-second budget.
- A health failure is a deployment failure, not an active application.

### Logs

- Docker's multiplexed log stream must be demultiplexed by the agent; the agent
  never shells out to `docker logs`.
- Maximum 200 lines and 64 KB per result.
- Logs are returned on success and failure.
- Logs are visible to organization members with application view permission and
  are stored on the deployment record.
- Logs are never used for authorization decisions.

### Timeouts and TTLs

| Operation | Command TTL | Agent budget |
| --- | --- | --- |
| `application.deploy` | 900 s | pull ≤ 6 min (two candidates), health ≤ 60 s, cleanup ≤ 30 s |
| `application.start` / `stop` | 120 s | Docker call ≤ 10 s |
| `application.delete` | 180 s | Docker call ≤ 10 s |
| `application.inspect` / `logs` | 60 s | Docker call ≤ 10 s |

The agent must finish under the command TTL. A pull that exceeds the budget fails
with a sanitized registry error.

### Replay and idempotency

- `application.deploy` is idempotent by `deployment_id` and container name.
- If the agent restarts during a deploy and the stored outcome is `executing`,
  it inspects the named container and continues the health check instead of
  reporting a false failure, when the container exists.
- If the deployment container does not exist, replaying a failed/executing
  deploy reports a failure; the control plane keeps the previous version.
- No deployment secret exists, so the deploy result may be replayed without a
  redaction problem.

---

## 6. Control-plane domain

### New domain

`App\Domains\Applications`

```text
Models/Application.php
Models/Deployment.php
Models/DeploymentEvent.php
Enums/ApplicationStatus.php
Enums/DeploymentStatus.php
Actions/CreateApplication.php
Actions/DeployApplication.php
Actions/RollbackApplication.php
Actions/StartApplication.php
Actions/StopApplication.php
Actions/RefreshApplication.php
Actions/DeleteApplication.php
Actions/HandleApplicationCommandResult.php
Presenters/ApplicationPresenter.php
Presenters/DeploymentPresenter.php
```

### Actions

`CreateApplication`:

1. Check environment belongs to the project and organization.
2. Check server belongs to the organization and is reachable with Docker.
3. Validate the image, port, health path and unique name/slug.
4. In one transaction:
   - create the application with status `deploying`;
   - create the first deployment with status `deploying`;
   - issue `application.deploy`;
   - set `last_command_id` and `command_id`;
   - record audit events.
5. Return the application and deployment.

`DeployApplication`:

- Lock the application.
- Reject transitional states.
- Create a deployment with the current image and a new container name.
- Store `previous_container_name`, `previous_status` and previous deployment ID
  in deployment metadata.
- Set the application to `deploying` and issue the command.

`RollbackApplication`:

- Lock the application and the target deployment.
- Require the target deployment to belong to the application and have an image.
- Create a new deployment with `metadata.rollback_of_deployment_id`.
- Issue a deploy command exactly like `DeployApplication`.

`StartApplication` / `StopApplication` / `RefreshApplication` /
`DeleteApplication`:

- Follow the resource-domain pattern: lock, validate state, issue a typed
  command, set a transitional status, clear `last_error`.
- Delete soft-deletes the application only after the agent reports the container
  removed.
- Refresh is an inspect command and can recover a stale status.

### Command-result handling

`HandleApplicationCommandResult`:

- Ignore non-application operations.
- Find the application by payload `application_id`.
- Require the application's `last_command_id` to match, otherwise ignore a stale
  result.
- Require the command server to match the application server.
- `deploy` success:
  - validate `container_id`, `container_name`, `host`, `port`, `status`, `logs`;
  - set the application active with the new container and image;
  - set the deployment active with logs and finished_at;
  - mark the previous active deployment superseded;
  - record deployment events and audit `ApplicationDeployed`;
  - record a warning event when the previous container was not removed.
- `deploy` failure:
  - set the deployment failed with logs and error;
  - restore the application's previous status when a previous container exists;
  - keep the previous container fields;
  - set `last_error` to the failure message;
  - record events and audit `DeploymentFailed`.
- `start` success → active; failure → failed.
- `stop` success → stopped; failure → active with `last_error` (the container may
  still be running; refresh can reconcile).
- `delete` success → `deleted`, soft-delete the application.
- `inspect` success → running/stopped/missing mapping; missing → failed with a
  clear message.
- `logs` success → store on the latest deployment and record an event.

### Reconciliation

`applications:reconcile`, scheduled every minute with a five-minute overlap lock:

- Process applications with transitional statuses.
- Missing `last_command_id` → fail the application and current deployment.
- Missing command → fail with a clear message.
- Completed command → re-run the result handler; fail if the application is
  still transitional.
- Failed/expired/cancelled terminal command → expire the command record and fail
  the application/deployment. Restore the previous version when the deployment
  metadata says one exists.
- Verbose output lists each application, status, command status and expiry.

### Policies and permissions

- `view`: any active organization member.
- `create` / `deploy` / `rollback` / `delete`: owner, admin and developer
  (roles with deploy permission).
- `start` / `stop` / `refresh`: roles with operate or deploy permission.
- All routes are organization-scoped and return 404 across tenants.
- Every mutation is audited.

### API/UI routes

```text
GET    /projects/{project}/applications
POST   /projects/{project}/applications
GET    /projects/{project}/applications/{application}
POST   /projects/{project}/applications/{application}/deploy
POST   /projects/{project}/applications/{application}/rollback/{deployment}
POST   /projects/{project}/applications/{application}/start
POST   /projects/{project}/applications/{application}/stop
POST   /projects/{project}/applications/{application}/refresh
DELETE /projects/{project}/applications/{application}
```

The existing server command API is not used for application mutations. They are
issued only by the application domain with its own ownership checks.

---

## 7. UI/UX plan

The backend must exist before the screens are built, per the project rule.

### Project tab

Add an **Applications** tab before Databases in the project workspace. The tab
page is a dedicated Inertia page, not another block in `Projects/Show.jsx`.

### Applications list

- Real count, search, status filter and sort.
- Each row: name, image, status badge, environment, server, `host:port`, latest
  deployment status.
- Actions: Deploy, Start, Stop, Refresh, Delete, Open.
- Empty state with a create action.
- Poll only while at least one application or deployment is transitional.
- No fabricated uptime, traffic or health numbers.

### Create application

Fields:

- name;
- image (required, with example placeholder);
- container port (default 3000);
- health path (optional, placeholder `/up`);
- environment;
- server.

Validation messages come from the backend. The first deployment is created
atomically with the application. After submission the UI shows the real
`deploying` state and polls for the agent result.

### Application detail

- Name, status, image, environment, server, container name, container ID,
  `host:port`, health path.
- Actions: Deploy current image, Rollback, Start, Stop, Refresh, Delete.
- Latest error from the real agent/reconciliation result.
- Deployment history table: image, status, requested/finished times, error,
  rollback action.
- Expandable logs for each deployment. Logs are rendered in a monospace
  scrollable block with a copy button. Empty logs show "No logs were returned."
- Deployment events timeline.
- Poll while the application or a deployment is transitional.

### Rollback

- A previous deployment with an image gets a Rollback action.
- Confirmation explains that a new deployment is created from that image.
- The UI never edits deployment history; it creates a new deployment.

### Delete

- Confirmation explains that the container is removed and the application is
  soft-deleted, while deployment history remains.
- No "deleted" row remains in the active list.

### Design system

Use existing `PageSection`, `StatusBadge`, `StatCard`, `EmptyState`,
`ConfirmDialog`, `Field`, `Button`, `Icon`, `CopyField` and the light-first
tokens in `docs/ui-ux/DESIGN_SYSTEM.md`. No new visual language.

---

## 8. Security and guardrails

- Tenant isolation on every query, action, policy and route.
- No arbitrary shell, Docker API path, container command, entrypoint, mount,
  privileged flag, host network or device.
- Published ports bind to `127.0.0.1` only.
- Health checks are restricted to the container's own loopback port and an
  HTTP path. No arbitrary URL fetch.
- Image references are validated in both the control plane and the agent.
- Logs are bounded and shown only to organization members with view permission.
- Errors are sanitized: no registry URLs with signatures, no credentials, no
  host paths.
- Every mutation is audited.
- Command payloads are validated by `AgentOperation::payloadRules()`.
- Idempotent deploy uses a unique deployment ID and container name; duplicate
  clicks are gated by transitional state and row locks.
- No application secrets are stored in this slice. Environment variables are a
  later deliverable and will use the existing encrypted credential pattern.

---

## 9. Failure and recovery matrix

| Failure | Agent behavior | Control plane behavior | UI |
| --- | --- | --- | --- |
| Image pull fails | Remove nothing; report sanitized error | Deployment failed; previous app restored | Show real error and logs |
| Container create fails | Remove partial new container | Deployment failed | Show error |
| Container exits before health | Read logs, remove new container | Deployment failed | Show logs |
| Health path returns 500 | Read logs, remove new container | Deployment failed | Show logs and health status |
| New container healthy, old removal fails | Report success with warning | App active; warning event | Show warning in deployment events |
| Agent restarts mid-deploy | Replay: inspect new container, continue or report failure | Command result applied; reconcile if no result | No stuck status |
| Command expires | Agent bounded by TTL; reconcile expires command | App/deployment failed or previous restored | Terminal state |
| Server unreachable | Agent cannot poll | Commands expire; reconcile fails | Error |
| User deletes app mid-deploy | Delete command queued or agent removes container | App soft-deleted after result | App disappears |
| Deployment result lost | Agent store replays the idempotent deploy | Reconcile or late result | Eventually terminal |

No path may leave an application or deployment permanently transitional without
a reconciliation action and an audit record.

---

## 10. Testing plan

### Go

- Image reference validation: valid tags, digests, registries, rejected shell
  characters and empty references.
- Docker Hub mirror-first candidate order; explicit registry images unchanged.
- Application deploy creates the expected container spec: image, labels, port
  binding, restart policy, no privileged/host-network/mount options.
- Health check success and failure using an `httptest` server.
- Old container removed only after health passes.
- Failed deploy removes the new container and leaves the old one running.
- Idempotent retry with an existing deployment container.
- Stop/start/delete missing-container behavior.
- Docker log stream demultiplexing and line bounding.
- Executor payload parsing and failure reporting.
- Replay recovery for an interrupted deploy.

### PHP

- Create application issues a typed deploy command and creates the first
  deployment.
- Validation: image, port, health path, duplicate name/slug, foreign
  environment/server, unreachable server.
- RBAC: viewer cannot create/deploy/delete; developer can; cross-tenant 404.
- Deploy result activates the application and deployment, stores logs, marks the
  previous deployment superseded.
- Failed deploy restores the previous active application and records the failed
  deployment.
- Rollback creates a new deployment with the previous image.
- Start/stop/delete/refresh are agent-driven.
- Command result endpoint handles application operations.
- Reconciliation: missing, expired, failed and completed commands.
- Project/application pages expose only safe props.
- Audit log entries for every mutation.

### Build and browser

- `npm run build` passes.
- Inertia feature tests assert the real props.
- Manual Playwright/Chrome screenshot review of the Applications list, create
  form, detail page, deployment history and logs after the backend is live.
- No fake data in screenshots; use a real agent/server or an explicitly labelled
  local fixture environment for UI review only, never in production docs.

### Real VPS acceptance

1. Update the agent and control plane with the installer.
2. Deploy a real public image, for example `nginx:1.27-alpine`, with container
   port `80` and health path `/`.
3. Confirm:
   - `docker ps` shows the managed application container;
   - the host port is bound to `127.0.0.1`;
   - `curl http://127.0.0.1:<port>/` returns the real response;
   - the UI shows Active only after the agent result;
   - deployment logs contain the real container output.
4. Redeploy a different image tag and confirm the old container is removed only
   after the new one is healthy.
5. Deploy a deliberately broken image and confirm the old version keeps running
   and the deployment is failed with logs.
6. Roll back and confirm a new deployment with the previous image becomes active.
7. Stop, start, refresh and delete; verify the container state each time.
8. Record the exact commands and outcomes in `PROGRESS.md`.

---

## 11. Delivery sequence

Each step is a separate commit with tests.

1. **Plan and docs** — this file; update `IMPLEMENTATION_PLAN.md`, `README.md`
   and `PROGRESS.md` to reflect the Phase 2 exception and first slice.
2. **Schema and models** — migrations, enums, models, factories, relations,
   casts, soft deletes.
3. **Agent application package** — types, validation, image candidates, Docker
   deploy/start/stop/delete/inspect/logs, health checks, log demux, tests.
4. **Agent protocol and executor** — new operations, payload parsing, TTLs,
   replay recovery, wiring, tests.
5. **Control-plane actions** — create/deploy/rollback/start/stop/refresh/delete,
   command issuance, policies, audit.
6. **Command results and reconciliation** — result handler, deployment events,
   `applications:reconcile`, scheduler, tests.
7. **HTTP and Inertia** — controllers, routes, presenters, project tab, list,
   create, detail, deployment history, logs, actions, polling, build.
8. **Docs and release** — architecture, API, agent protocol, design system,
   progress, version `1.3.0`, agent release assets.
9. **VPS acceptance** — run section 10, fix real issues, record evidence.

Do not start step 7 until steps 2–6 have real backend behavior and tests.

---

## 12. Open decisions and defaults

| Decision | Default for this slice |
| --- | --- |
| Default container port | `3000`, editable |
| Health path | Optional; no path means running + Docker health if present |
| Rollout strategy | New container first, remove old after health |
| Host binding | `127.0.0.1` only |
| Image sources | Public registries; Docker Hub mirror-first |
| Registry auth | Not supported |
| Environment variables | Not in this slice |
| Persistent volumes | Not in this slice |
| Logs | 200 lines / 64 KB at deployment time |
| Deployment history | Kept after application soft delete |
| Rollback | New deployment from a previous image |
| App delete | Force-remove container, keep history |
| Multi-server placement | Not in this slice; user selects a ready server |

Any change to these defaults must be recorded in this file and in
`PROGRESS.md`.

---

## 13. Acceptance criteria

This slice is complete when:

- A project member can create an application from a prebuilt image and the
  first deployment is recorded.
- The agent pulls the image, creates a loopback-bound container, starts it and
  performs the configured health check.
- The UI shows Active only after a real agent result, with the real container
  name, ID, host port and image.
- Deployment history shows status, timestamps, error and bounded real logs.
- A successful redeploy replaces the old container without downtime; a failed
  deploy leaves the old version running.
- Rollback creates a new deployment from a previous image.
- Start, stop, refresh and delete are real agent operations.
- Expired/missing commands and agent restarts reconcile to a terminal state.
- Tenant isolation, RBAC, input validation and audit tests pass.
- The Go suite, PHP suite and frontend build pass.
- The VPS acceptance run in section 10 is recorded in `PROGRESS.md`.
- No screen, status, log or metric is fabricated.

---

## 14. Explicit non-goals for the first release

Do not add these to the first application slice:

- a GitHub install button that has no App backend;
- a "Build" button that does not invoke BuildKit;
- fake deployment progress percentages;
- fake log lines;
- an environment-variable editor that stores plaintext secrets;
- a domain field that does not configure a proxy;
- a rollback button that only changes a database status.

They can be added when their real backend exists.
