# CloudForge implementation plan

**Plan date:** 2026-11-12
**Baseline:** see `docs/implementation/REPOSITORY_AUDIT.md`.
**Execution rule:** a milestone is complete only when its acceptance tests pass
and the verification level is recorded in `docs/implementation/PROGRESS.md`.
Code presence is not completion.

Milestones follow the dependency-aware order required by the master prompt.
Milestone 1 is the current implementation target; later milestones are defined
here so their prerequisites are explicit.

---

## Status legend

| Status | Meaning |
| --- | --- |
| Not started | No implementation work has begun. |
| In progress | Code exists but acceptance criteria are not met. |
| Implemented | Code, migration and automated tests exist; acceptance tests pass locally. |
| Tested | Implemented plus negative, idempotency and isolation tests pass. |
| Verified in real infrastructure | Exercised on a real VPS with real Docker/PostgreSQL/DNS/TLS. Never claimed from local tests alone. |

---

## Milestone 0 — repository audit and baseline

**Dependencies:** none.
**Deliverables:** `REPOSITORY_AUDIT.md`, this plan, baseline test evidence.
**Acceptance criteria:**

- Architecture, implemented, partial and missing functionality documented with
  real file references.
- Security risks, schema gaps, frontend gaps and technical debt recorded.
- Baseline PHP and Go results recorded, including the seven failing enrollment
  tests and their root cause.
- No production code changed before the audit is written.

**Status:** Implemented (documents written; baseline captured).

---

## Milestone 1 — self-hosted bootstrap

**Goal:** installing CloudForge on a VPS automatically registers that VPS as the
first managed server, with a real agent identity and real host inventory, and no
manual dashboard registration.

**Dependencies:** Milestone 0; a running control-plane deployment with a
reachable loopback URL; a Linux host with systemd and, for Docker capability,
a readable Docker Unix socket; release assets for the agent.

### 1.1 Schema and models

- Make `servers.organization_id` and `server_enrollments.organization_id`
  nullable.
- Add `servers.machine_id` (unique, nullable), `servers.is_bootstrap` (default
  false) and `servers.claimed_at` (nullable).
- Add `server_enrollments.purpose` (`installation` or `bootstrap`, default
  `installation`).
- Fix `expires_at` (required, no default) and `consumed_at` (nullable, no
  default); remove the duplicate `created_by_user_id` index.
- Add a guarded migration for existing development databases that makes
  `organization_id` nullable and adds the new columns only when absent.
- Update `Server`, `ServerEnrollment`, factories and `ServerResource`.
- Make `ServerPolicy` return false for unclaimed servers instead of
  dereferencing a null organization.

**Acceptance:** fresh `migrate:fresh` and an upgrade from the previous schema
both succeed; `ServerEnrollment::isUsable()` is true for a token issued now;
unclaimed servers are invisible to tenant queries and policies.

### 1.2 Host inspection

- `HostInspector` reads OS, architecture, hostname, CPU count, total memory,
  total and available disk, usable addresses, machine-id and Docker version
  without executing a shell.
- Docker probing uses only the local Unix socket and `GET /version` plus
  `GET /info`.
- Missing or unreadable sources return explicit nulls/false, never fabricated
  values.
- Machine-id is required for bootstrap. A 32-character hex override can be
  supplied through `CLOUDFORGE_MACHINE_ID` for the control-plane inspector and
  the agent, and is rejected when malformed.

**Acceptance:** unit tests cover parsing and fallback for OS release, meminfo,
CPU count, Docker response parsing and machine-id discovery.

### 1.3 Bootstrap provisioning

- `PrepareBootstrapServer` is idempotent by machine-id. Re-running updates host
  facts, preserves the server ID, and never creates a second bootstrap record.
- It creates the server unclaimed with `is_bootstrap = true`, roles `control`
  and `application`, and status `installation_pending`.
- It issues a node-scoped bootstrap enrollment token with a short TTL from
  configuration. If an active certificate already exists, it reports the node as
  already enrolled and issues no new token.
- `cloudforge:bootstrap` writes a `0600` JSON status file and a `0600`
  shell-sourceable env file containing the token, server ID and control-plane
  URL. Token values never appear on stdout or in the JSON status file.
- `cloudforge:bootstrap:status` reports real state; `cloudforge:bootstrap:claim`
  claims unclaimed bootstrap servers for an organization.
- `ClaimBootstrapServers` is called inside `CreateOrganization` and claims only
  unclaimed bootstrap rows, writes `claimed_at`, and audits the claim.
- Enrollment auto-claims when exactly one organization exists and the enrollment
  purpose is bootstrap.

**Acceptance:** tests prove idempotent re-runs, one server row, hashed token,
node binding, claim on organization creation, auto-claim with one organization,
no claim when multiple organizations exist, and audit records.

### 1.4 Secure enrollment binding

- Enrollment accepts and validates `machine_id`.
- Bootstrap enrollments must present the machine-id recorded on the server.
  A mismatch returns HTTP 422 and does not consume the token, issue a
  certificate or change the server.
- Installation enrollments keep the existing operator-scoped behaviour.
- Enrollment with a null organization audits without an organization and does
  not fail.
- Existing certificate rotation and revocation continue to work.

**Acceptance:** positive bootstrap enrollment, mismatch rejection, token
single-use, expired-token rejection, certificate uniqueness per server, and
rotation/revocation regression tests pass.

### 1.5 Agent

- The Go agent reads `/etc/machine-id` (fallback `/var/lib/dbus/machine-id`) and
  includes `machine_id` in the enrollment request.
- A 32-character hex `CLOUDFORGE_MACHINE_ID` override takes precedence, is
  normalized to lowercase, and is rejected with an explicit error when malformed.
- Missing machine-id does not crash the agent; the field is omitted.
- Existing enrollment, signature, command and metrics tests remain green.
- The agent never logs the bootstrap token or machine-id at info level.

**Acceptance:** Go tests for machine-id discovery, override normalization and
enrollment payload; full `go test ./...` and `go vet ./...` pass.

### 1.6 Installer and wrapper

- `public/install.sh` gains `CLOUDFORGE_BOOTSTRAP=1` mode:
  - sources `CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH` (default
    `/etc/cloudforge/bootstrap.env`) with `set -a`;
  - uses the server ID and token from that file;
  - refuses to source a file whose mode is not `0600` or that is unreadable;
  - skips enrollment when an agent identity already exists, unless
    `CLOUDFORGE_FORCE_ENROLL=1`;
  - deletes the credential after the enrollment block, whether enrollment ran
    or was skipped;
  - passes `CLOUDFORGE_MACHINE_ID` through to agent enrollment when exported.
- `infrastructure/scripts/bootstrap-node.sh` wraps a real installation:
  1. runs `php artisan cloudforge:bootstrap` as the PHP user with explicit
     credential and status paths;
  2. runs the agent installer from the local application directory with
     `CLOUDFORGE_BOOTSTRAP=1`;
  3. refreshes the installation from `agent.env` when an identity already
     exists;
  4. prints `cloudforge:bootstrap:status`.
  Re-running the wrapper must be safe.
- Installer tests cover file permissions, token absence from `agent.env` and
  stdout, cleanup and re-run behaviour using a POSIX shell harness; when the
  host filesystem does not preserve `0600`, the real-mode assertion is reported
  as skipped rather than passed.

**Acceptance:** shell syntax passes, tests pass, and the wrapper is documented
as the only supported automatic registration path.

### 1.7 UI and documentation

- Onboarding shows up to five unclaimed bootstrap nodes (name, hostname,
  machine-id prefix, status, agent enrollment state and token expiry) and states
  that creating an organization claims them.
- Server list/show mark bootstrap nodes and show the origin and `claimed_at`.
- Dashboard displays the bootstrap node after claim through normal
  organization-scoped queries.
- `docs/deployment/control-plane.md`, `docs/agent/installation.md` and
  `docs/agent/protocol.md` exist and match the implementation.
- Architecture overview and agent README stop claiming container/image/network/
  volume counts.
- `docs/implementation/PROGRESS.md` records every requirement with its
  verification level.

**Acceptance:** `npm run build` succeeds; Inertia pages render the new props;
documentation links resolve.

### 1.8 One-command installation

- Add a root `install.sh` for clean Ubuntu 22.04/24.04 and Debian 12 hosts.
- Provision PHP 8.4, PostgreSQL, Redis, Nginx, Node.js, Docker, Composer and Certbot.
- Create the database and role, write a production `.env`, build assets and run
  migrations without manual steps.
- Obtain a Let's Encrypt certificate when a domain and email are supplied.
- Configure the scheduler and queue worker.
- Build the Go agent from source and run the existing bootstrap wrapper.
- Leave the first-run `/register` page ready for the owner account.
- Keep all secrets out of stdout and the install log.
- Support an explicit HTTP-only development mode with loopback agent enrollment.

**Acceptance:** `bash -n`, the one-command shell contract harness and the full
PHP suite pass; no secret is committed or printed; a real clean-VPS run remains
required before claiming real-infrastructure verification.

### Milestone 1 acceptance tests

- `BootstrapProvisioningTest`: first run creates one unclaimed bootstrap server,
  a hashed bootstrap enrollment and `0600` credential files; second run updates
  the same server and does not duplicate.
- `BootstrapCredentialTest`: credential and status contents, modes, atomic
  replacement and stale-credential deletion.
- `BootstrapCommandTest`: `cloudforge:bootstrap`, status and claim commands,
  JSON output and token non-disclosure.
- `AgentEnrollmentTest`: bootstrap machine-id matching, mismatch rejection
  without consuming the token, single-use tokens, auto-claim with one
  organization, claim on organization creation, and existing enrollment,
  rotation and revocation regressions.
- `BootstrapWebTest`: onboarding lists unclaimed nodes and organization creation
  claims them; server pages expose bootstrap state.
- `HostInspectorTest`: host parsing, Docker `/version` and `/info` probing,
  machine-id discovery and override normalization.
- `InstallerScriptTest` and `tests/shell/installer_test.sh`: syntax, mode gate,
  token absence from `agent.env`, cleanup, re-run behaviour and local-binary
  handling.
- `tests/shell/one_command_installer_test.sh`: domain validation, `.env`
  read/write, special-character handling and architecture detection.
- `RbacTest` / `TenantIsolationTest`: unclaimed bootstrap server denied to every
  role; claimed server visible only to its organization.
- Go: machine-id discovery, override normalization and enrollment payload tests.

**Explicit non-goals for Milestone 1:** application deployment, GitHub, builds,
databases, domains, TLS, real-time streaming and multi-VPS placement.

**Verification boundary:** local automated tests can prove code behaviour. They
cannot prove a clean VPS installation until run on a real Linux VPS with systemd,
release assets and a reachable control plane. That remains "not verified in real
infrastructure" until such a run is performed.

**Status:** Tested locally (PHP, Go, shell harness and frontend build). Not yet
verified in real infrastructure: no clean VPS installation, systemd run,
PostgreSQL run or three-node enrollment has been performed.

---

## Milestone 2 — application deployment

**Dependencies:** Milestone 1; typed agent mutations for images/containers;
BuildKit availability; GitHub App credentials.
**Scope:** applications, environments, deployments, releases, build jobs,
environment variables; GitHub App install and repository import; framework
detection; Laravel/Node/static/Docker presets; BuildKit build; container
create/start/health; deployment logs and rollback.
**Acceptance:** a real Laravel repository can be imported, built and deployed to
a container on the bootstrap node, health-checked, and rolled back, with
deployment records and logs reflecting actual outcomes.
**Status:** Not started. Blocked on GitHub App credentials and Docker for full
verification.

---

## Milestone 3 — database platform

**Dependencies:** Milestone 2 container/network mutations; PostgreSQL image
availability.
**Scope:** PostgreSQL, MySQL and Redis; shared clusters and dedicated instances;
one-click provisioning; credential generation and encrypted storage; attachment
to applications; private networking; backups.
**First slice delivered:** PostgreSQL 16 and Redis 7 containers are provisioned
through typed agent Docker operations, bound to loopback, with persistent
volumes, encrypted credentials, start/stop/delete/refresh and
reconciliation. MySQL, application attachment, shared clusters and backups
remain.
**Acceptance:** creating a PostgreSQL instance produces a real running database,
credentials are available to authorized members and every view is audited, an application receives working connection
variables, and deletion/backup restore behave correctly.
**Status:** In progress. PostgreSQL/Redis control-plane and agent code is tested
locally; real Docker container creation, image pulls and credential use are not
yet verified on a VPS. MySQL, attachment and backups are not started.

---

## Milestone 4 — Laravel runtime

**Dependencies:** Milestones 2 and 3.
**Scope:** web process, queue workers, Horizon, scheduler, Octane, Reverb,
environment variable management, process scaling.
**Acceptance:** a Laravel application runs with a web process, a queue worker and
a scheduler, environment changes redeploy safely, and logs are real.
**Status:** Not started.

---

## Milestone 5 — domains and TLS

**Dependencies:** Milestone 2; proxy mutation handlers; DNS access for
verification.
**Scope:** custom domains, DNS verification, reverse proxy, automatic HTTPS,
WebSocket routing, certificate renewal.
**Acceptance:** a domain resolves to the node, a certificate is issued only after
verification, HTTPS works, and renewal is observable.
**Status:** Not started. Blocked on DNS/TLS infrastructure for verification.

---

## Milestone 6 — real-time dashboard

**Dependencies:** Milestones 2–5; broadcasting infrastructure.
**Scope:** WebSocket events, deployment streaming, live logs, metrics, operation
progress, server status.
**Acceptance:** a deployment started in one browser updates in another without a
refresh, and reconnects after a dropped connection without losing events.
**Status:** Not started.

---

## Milestone 7 — multi-server orchestration

**Dependencies:** Milestones 1–3; placement schema.
**Scope:** node groups, roles, placement, resource reservation, cross-server
deployment, worker scaling, draining.
**Acceptance:** a second VPS enrols through the dashboard, receives a deployment,
and draining migrates or stops workloads with progress reported.
**Status:** Not started.

---

## Milestone 8 — production hardening

**Dependencies:** all previous milestones.
**Scope:** security tests, load tests, backup/restore, failure recovery,
monitoring, installation verification, documentation.
**Acceptance:** documented failure scenarios are exercised and recovered on real
infrastructure; no known critical security finding remains.
**Status:** Not started.

---

## Product UI/UX implementation

**Scope:** the approved CloudForge UI direction in `docs/ui-ux/`.
**Implemented:** semantic tokens, light/dark theme switch, grouped collapsible
sidebar, breadcrumbs, account menu, responsive mobile drawer, shared page/stat/
credential components, real-data dashboard with attention queue and managed
databases, and an organization-wide Databases workspace with search, filters,
sorting and lifecycle actions.
**Blocked:** application and deployment screens (no backend models, routes or
state), domains, backups, networking and MySQL. These are documented as missing
rather than represented by static UI.
**Status:** Design foundation, shell, dashboard and database workspace tested
locally. No browser end-to-end or formal contrast audit has been run.

---

## Cross-cutting rules

1. **No fake status.** Every operation returns a real result or an explicit
   failure. UI states map to persisted state.
2. **Typed agent mutations only.** No shell execution, no arbitrary Docker API
   paths, no TCP Docker endpoints.
3. **Tenant isolation first.** New tables carry `organization_id`; policies and
   queries enforce it; API returns 404 for cross-tenant resources.
4. **Idempotency and recovery.** Long-running operations use durable command
   records with dedupe keys and explicit terminal states.
5. **Secrets.** Tokens hashed, credentials encrypted, bootstrap credential files
   `0600`, never in browser JS, logs or `agent.env`.
6. **Tests per milestone.** PHP feature tests, Go tests and installer checks run
   before a milestone is reported. Failures are reported, not hidden.
7. **Documentation per milestone.** Architecture, API, installation and
   operations docs are updated with the code.
8. **Verification honesty.** Local green tests are "Implemented/Tested"; only a
   real VPS run earns "Verified in real infrastructure".
