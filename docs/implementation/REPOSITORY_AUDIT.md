# CloudForge repository audit

**Audit date:** 2026-11-12
**Repository state:** local working tree, no git repository initialised, no commit history available.
**Scope:** entire repository at `apps/control-plane`, `apps/agent`, `infrastructure` and
`docs`, including source, schema, routes, frontend pages, tests, installer and
systemd unit.

This audit separates **code present** from **workflow verified**. A feature is
marked implemented only when its complete workflow exists and has a passing
automated test. Features that only have a model, route, controller, page or
button are classified as partial. Nothing in this repository has been verified
against real VPS, Docker, PostgreSQL, DNS or GitHub infrastructure.

> **Post-Milestone 1 note:** this document is the pre-implementation baseline.
> Gaps, defects and incorrect claims recorded below were addressed during
> Milestone 1 where the plan covers them. Current verification status, including
> what remains unverified, is recorded in
> [`PROGRESS.md`](PROGRESS.md). Baseline test failures remain in this document as
> historical evidence.

---

## 1. Existing architecture

CloudForge is a two-tier self-hosted platform:

```text
Browser (Inertia + React 19)
        │  session cookie, CSRF
        ▼
Laravel 13.42 control plane (PHP 8.4)
  ├── SQLite for local dev/test; PostgreSQL and Redis intended for production
  ├── organizations, memberships, projects, environments
  ├── servers, enrollments, certificates, metrics, commands, audit log
  ├── certificate authority (RSA-4096 CA, RSA-2048 agent keys)
  └── queued jobs and scheduler
        ▲
        │  outbound HTTPS only; RSA-SHA256 request signatures
        │  X-CloudForge-Fingerprint / -Timestamp / -Nonce / -Signature
        │
Go 1.24 agent (standard library only)
  ├── enrollment, identity persistence, rotation check
  ├── heartbeat, inventory, metrics, command long-poll
  ├── durable command dedupe in commands.json
  └── read-only Docker inspection over a Unix socket
```

Key evidence:

- Stack and versions: `apps/control-plane/composer.json`,
  `apps/control-plane/package.json`, `apps/agent/go.mod`.
- Control-plane composition and policy registration:
  `apps/control-plane/app/Providers/AppServiceProvider.php`.
- Agent identity and signatures:
  `apps/agent/internal/identity/`, `apps/agent/internal/client/client.go`,
  `apps/control-plane/app/Domains/Infrastructure/Services/AgentRequestVerifier.php`.
- Certificate authority:
  `apps/control-plane/app/Domains/Infrastructure/Services/AgentCertificateAuthority.php`.
- Durable commands:
  `apps/control-plane/app/Domains/Operations/`, ADR
  `docs/architecture/adr/0003-durable-command-model.md`.
- Architecture narrative: `docs/architecture/overview.md`.

The current agent has exactly four operations: `server.inspect`,
`server.health`, `metrics.collect` and `agent.restart`
(`apps/agent/internal/commands/executor.go`). It cannot yet create containers,
images, networks, volumes, databases, proxies or TLS certificates.

---

## 2. Implemented functionality

The following workflows exist end to end in code and have passing or
near-passing automated coverage at baseline (see §11):

| Capability | Source | Tests |
| --- | --- | --- |
| Session authentication, login/logout, password reset absent | `app/Http/Controllers/Auth/`, `tests/Feature/ApplicationTest.php` | Application, Registration |
| Invitation-gated and first-user registration | `app/Http/Controllers/Auth/RegisterController.php`, `config/cloudforge.php` | `RegistrationTest` |
| Organizations, membership roles, invitation lifecycle | `app/Domains/Organizations/`, `app/Http/Controllers/Web/MemberController.php` | `OrganizationInvitationTest`, `RbacTest` |
| Tenant isolation on web and API | `app/Support/OrganizationContext.php`, policies in `app/Policies/` | `TenantIsolationTest` |
| Projects and environments | `app/Domains/Projects/`, `app/Http/Controllers/Web/ProjectController.php` | `ApplicationTest` |
| Organization-scoped API tokens (`cf_` prefix, abilities) | `app/Domains/Organizations/Models/OrganizationApiToken.php`, `app/Http/Middleware/` | `OrganizationApiTest` |
| API routes under `/api/v1`, resources, pagination | `routes/api.php`, `app/Http/Resources/` | `OrganizationApiTest` |
| Server registration, lifecycle states, roles, capabilities | `app/Domains/Infrastructure/`, `app/Http/Controllers/Web/ServerController.php` | `ApplicationTest`, `RbacTest` |
| Single-use, hashed enrollment tokens | `IssueEnrollmentToken`, `RedeemEnrollmentToken`, migration `2026_11_11_000003` | `AgentEnrollmentTest` (7 baseline failures, see §11) |
| CSR signing, certificate persistence, rotation, revocation | `AgentCertificateAuthority`, `RotateServerCertificate`, `RevokeServerCertificates` | `AgentEnrollmentTest`, `AgentAuthenticationTest` |
| Signed agent authentication, timestamp skew, nonce replay cache | `AgentRequestVerifier`, `AgentAuthenticate` middleware | `AgentAuthenticationTest` |
| Heartbeats, inventory, capability storage | `RecordHeartbeat`, `RecordInventory`, agent heartbeat/inventory loops | `AgentHeartbeatTest` |
| Metrics recording and retention pruning | `RecordMetrics`, `PruneServerMetrics` | `AgentHeartbeatTest`, scheduler |
| Durable commands: issue, acknowledge, redeliver, complete, expire, cancel, idempotency | `AgentCommandService`, `AgentCommand`, agent `commands` package | `AgentCommandTest` (17 tests) |
| Audit log for authentication, organization, project, server, token and agent events | `AuditLogger`, `AuditAction`, `AuditLog` | `AuditLogTest` |
| Unreachable-server detection and recovery state preservation | `MarkUnreachableServers`, `RecordHeartbeat` | `AgentHeartbeatTest` |
| Agent packaging: static binaries, systemd unit, checksums | `apps/agent/Makefile`, `apps/agent/packaging/package.sh` | build scripts only; no CI |
| Agent installer with checksum verification, unprivileged enrollment, templated unit | `apps/control-plane/public/install.sh` | `sh -n` only; no automated shell tests |

The login page, dashboard, server list, project pages, members, API tokens,
audit log and organization settings are all backed by real queries and
mutations. The dashboard does not fabricate status: counts come from
`servers.status` and metrics rows.

---

## 3. Partially implemented functionality

| Area | What exists | What is missing |
| --- | --- | --- |
| Docker inventory | Agent inspects `/version` and `/info`; control plane stores version, API version and storage driver (`RecordInventory`) | No container, image, network or volume counts are collected or stored. The stale claims in `apps/agent/README.md` and `docs/architecture/overview.md` were corrected in Milestone 1; see `PROGRESS.md`. |
| Agent operations | Typed command executor with four read-only operations | No mutation handlers for containers, builds, databases, proxies or TLS. Required for Milestones 2–7. |
| Server registration UX | Create server form, enrollment command, certificate management | No automatic local node registration. Installation still requires an operator to create a server in the dashboard first. |
| Installer | Downloads releases, verifies SHA-256, creates service user, writes `agent.env`, enrolls, starts systemd | No control-plane installation wrapper, no `0600` local bootstrap credential handoff, no machine-id binding, no automated idempotency test. Re-running is safe for the agent identity but has no test. |
| Deployment documentation | `docs/api/README.md`, `docs/architecture/overview.md`, four ADRs | Links to `docs/agent/protocol.md` and `docs/deployment/control-plane.md` are dead; `docs/agent/installation.md` does not exist. |
| Metrics | CPU, memory, disk, network samples and 14-day pruning | No live streaming, charts are limited to existing sparkline component. |
| API | Server/project/audit/token endpoints | No deployments, applications, databases, domains, builds or operations endpoints. |
| Frontend product surface | Auth, onboarding, dashboard, servers, projects, members, tokens, audit, settings | No applications, deployment UI, database UI, domains, logs or real-time views. |

---

## 4. Missing functionality

Nothing in this section has source code beyond the descriptions in the master
prompt:

1. **Automatic local bootstrap** — detect the host, create the unclaimed server
   record, mint a node-scoped bootstrap credential, install/enrol the agent and
   surface the node after the first organization is created. This is Milestone 1
   and the first deliverable of this implementation.
2. **Application model and lifecycle** — applications, deployments, releases,
   build jobs, environment variables, process definitions.
3. **GitHub integration** — App installation, repository listing, webhook
   verification, branch selection.
4. **Framework detection** — Laravel, Node.js, static and Dockerfile presets.
5. **Build engine** — BuildKit invocation, build logs, image tagging, registry
   or local image handoff.
6. **Deployment engine** — container create/start/stop, health checks, traffic
   switch, rollback.
7. **Database platform** — PostgreSQL, MySQL and Redis provisioning, shared
   clusters, credentials, attachment and backups.
8. **Laravel runtime** — web process, queue workers, Horizon, scheduler, Octane,
   Reverb.
9. **Domains and TLS** — DNS verification, reverse proxy configuration,
   certificate issuance and renewal.
10. **Real-time dashboard** — WebSocket events for deployments, logs, metrics and
    operations.
11. **Multi-VPS orchestration** — node groups, placement, reservations, draining.
12. **Production hardening** — load tests, failure injection, backup/restore,
    monitoring, install verification.

---

## 5. Existing technical debt

1. **Broken enrollment defaults.** `server_enrollments.expires_at` and
   `consumed_at` default to `now()` in the create migration. Every new token is
   immediately expired and consumed. Seven `AgentEnrollmentTest` tests fail at
   baseline because of this (details in §11).
2. **Duplicate index declaration.** `created_by_user_id` is indexed by the
   foreign key definition and again explicitly, producing a duplicate index.
3. **Dead commented indexes.** `expires_at_index` index lines are commented out
   in both `server_enrollments` and `server_certificates`; the intent is unclear
   and the comments should be resolved.
4. **Documentation drift.** Agent README and architecture overview claim Docker
   counts that are not collected. Two linked documents are missing. The systemd
   unit in `infrastructure/systemd/` is a template with different content from
   the unit rendered by `public/install.sh`; there is no check that they agree.
5. **No Git history, no CI.** There is no `.git`, no workflow, no lint/test gate
   and no release provenance beyond `SHA256SUMS` produced locally.
6. **Installer is untested.** Only `sh -n` has been run. There are no tests for
   token leakage, file modes, re-run idempotency, partial failure or cleanup.
7. **No control-plane provisioning.** The repository documents how the agent is
   installed but not how Laravel, the database, queue worker, scheduler, web
   server and TLS are installed on the control-plane VPS.
8. **SQLite-only verified path.** PostgreSQL and Redis are intended but not
   exercised locally; no Docker daemon or PostgreSQL server is available in this
   environment.
9. **No machine identity.** Enrollment trusts the token and CSR common name.
   A leaked token can be redeemed from any host. Milestone 1 adds a machine-id
   binding for bootstrap tokens; installation tokens remain host-agnostic by
   design because they are operator-issued for a named server.
10. **Frontend build not part of the test suite.** `npm run build` exists but is
    not run by PHP tests.

---

## 6. Security risks

| Risk | Current state | Required treatment |
| --- | --- | --- |
| Token leakage through shell history or logs | Enrollment token is passed as an environment variable by the operator command; it is not written to `agent.env`. | Keep it out of argv and logs. Bootstrap must pass the token through a `0600` env file sourced by the wrapper, never print it, and delete it after use. |
| Token replay after host compromise | Tokens are single-use and hashed, but not bound to a host. | Bind bootstrap tokens to `/etc/machine-id` and verify it server-side at enrollment. Refuse a mismatch without consuming the token or issuing a certificate. |
| Unclaimed server visibility | `servers.organization_id` is currently non-null. | Milestone 1 makes it nullable for bootstrap only. Policies must return false for unclaimed servers; tenant queries must continue to scope by organization. |
| Agent privilege | Agent runs as `cloudforge-agent`, unit uses `NoNewPrivileges`, `ProtectSystem=strict`, `ReadWritePaths` limited to data dir and Docker socket. | Preserve. Future mutations must stay typed and allowlisted; no shell, no Docker TCP. |
| Docker socket access | Agent joins `docker` group and reads `/var/run/docker.sock`; no public socket exposure. | Preserve. Mutation handlers must validate payloads and never accept arbitrary Docker API paths. |
| Certificate authority | CA private key is stored under `storage/app/private/agent-ca` with application-level permissions. | Ensure production storage permissions and backups; do not expose the CA key through API or frontend. |
| Bootstrap command output | A new command will print status. | Redact tokens, hashes, private paths and machine identifiers from human output; JSON output must omit the token. |
| Rate limits | `agent-enroll` 10/min/IP; other limits configured. | Preserve and test that bootstrap uses the same endpoint and limits. |
| CSRF and session security | Laravel defaults plus Inertia middleware. | Preserve; do not add token-bearing URLs. |

No SQL injection, mass-assignment or IDOR findings were identified in the
reviewed code: models use `#[Fillable]`, queries are Eloquent-bound, policies
scope by organization and API routes resolve servers through the authenticated
token's organization. The absence of a formal penetration test is a limitation,
not a clean bill of health.

---

## 7. Architectural conflicts

1. **Bootstrap versus organization-scoped schema.** `servers.organization_id` is
   non-null and `ServerPolicy` dereferences `$server->organization`. A local node
   must exist before the first user and organization exist. The schema and
   policy need a deliberate unclaimed state rather than a fake organization.
2. **Enrollment audit scope.** `audit_logs.organization_id` is nullable, but
   `RedeemEnrollmentToken` passes `$server->organization` unconditionally. This
   works today only because every server has an organization. Bootstrap
   enrollment must audit with a null organization until the server is claimed.
3. **`IssueEnrollmentToken` requires an organization through the server.** It
   writes `organization_id` from the server. For a bootstrap server this must be
   null, and the action must accept a purpose and TTL.
4. **Documented Docker inventory versus implemented inventory.** The agent and
   control plane never collect counts. Documentation must be corrected rather
   than implementing counts just to satisfy prose.
5. **Installer ownership.** `public/install.sh` is served by the control plane,
   but a bootstrap installation runs before the control plane is reachable over
   public TLS. The bootstrap wrapper must run the same script from the local
   application directory with a loopback URL, not depend on a public download.
6. **No canonical control-plane deployment.** Documentation implies a deployed
   Laravel app but the repository has no provisioning automation. Milestone 1
   adds a wrapper around the agent installer; full control-plane provisioning is
   a deployment concern that must be documented honestly as a prerequisite.

---

## 8. Database schema gaps

Current migrations (7):

```text
0001_01_01_000000_create_users_table
0001_01_01_000001_create_cache_table
0001_01_01_000002_create_jobs_table
2026_11_11_000001_create_organizations_tables
2026_11_11_000002_create_projects_tables
2026_11_11_000003_create_servers_tables
2026_11_11_000004_create_operations_tables
```

Gaps relevant to Milestone 1:

- `servers.organization_id` non-null; needs nullable for unclaimed bootstrap.
- No `machine_id`, `is_bootstrap` or `claimed_at` on `servers`.
- `server_enrollments` has no `purpose` and no machine binding.
- `server_enrollments.expires_at` and `consumed_at` have wrong defaults.
- No unique constraint on `servers.machine_id`.
- No database-level guarantee that only bootstrap servers may be unclaimed.

Gaps for later milestones (not implemented in Milestone 1):

- No applications, deployments, releases, build jobs, environment variables,
  process definitions, database instances, database attachments, domains,
  certificates, proxy routes, node groups or reservations.
- No operation-progress table for long-running infrastructure work; commands are
  the only durable operation record.
- No soft-delete/retention policy for audit logs or command history.

---

## 9. Frontend/backend integration gaps

- Every existing button maps to a controller action and a real query or
  mutation. There are no placeholder "coming soon" buttons in the current pages.
- There is no screen for automatic bootstrap discovery. Onboarding only asks for
  an organization name. After Milestone 1 it must show the detected local node
  and explain that it will be claimed by the new organization.
- Server pages do not distinguish a bootstrap node from a manually registered
  node, and do not show `claimed_at`.
- Dashboard shows server counts and statuses but not "this is the node running
  the control plane".
- No frontend exists for applications, deployments, databases, domains, logs or
  real-time updates.
- Inertia shared props expose `currentOrganization`; any new page must follow
  that convention and not assume `organization`.
- The server list and dashboard are organization-scoped. An unclaimed bootstrap
  server is intentionally **not** listed in tenant pages until claimed; it is
  surfaced only through onboarding and the bootstrap status command.

---

## 10. Recommended implementation order

1. **Milestone 0 (this document and the plan).** Fix the baseline migration
   defect before writing new tests so the suite reflects real behaviour.
2. **Milestone 1 — self-hosted bootstrap.**
   - Nullable organization on servers/enrollments, bootstrap fields, purpose.
   - Host inspection service (OS, arch, hostname, CPU, memory, disk, addresses,
     Docker, machine-id).
   - Idempotent `PrepareBootstrapServer` keyed by machine-id.
   - Node-scoped, short-lived, single-use bootstrap enrollment with machine-id
     verification.
   - Claim on first organization creation or auto-claim when exactly one
     organization exists at enrollment.
   - `cloudforge:bootstrap`, `:claim`, `:status` commands and a `0600`
     credential handoff.
   - Installer bootstrap mode, cleanup and re-run idempotency.
   - Go agent machine-id reporting.
   - Onboarding and server UI surfacing.
   - PHP/Go tests and documentation.
3. **Milestone 2 — application deployment.** Application schema, GitHub App,
   repository import, framework detection, BuildKit build, container lifecycle,
   health checks, deployment history and rollback.
4. **Milestone 3 — database platform.** PostgreSQL first, then MySQL and Redis.
5. **Milestone 4 — Laravel runtime.** Web, workers, Horizon, scheduler, Octane,
   Reverb and environment management.
6. **Milestone 5 — domains and TLS.**
7. **Milestone 6 — real-time dashboard.**
8. **Milestone 7 — multi-server orchestration.**
9. **Milestone 8 — production hardening and verification.**

Dependencies: Milestone 2 needs typed agent mutations and a build/runtime
contract; Milestone 3 needs the same plus networking; Milestone 5 needs proxy
mutation handlers; Milestone 6 needs durable operation events; Milestone 7 needs
placement data and reservations. None of them can be honestly marked verified
without real Docker/VPS infrastructure.

---

## 11. Baseline verification

Commands were run on 2026-11-12 from a Windows workstation. PHP and Go tests run
locally; no Docker daemon, PostgreSQL server, DNS zone, TLS certificate or GitHub
App is available.

### PHP

```text
php artisan test --compact
tests: 131, passed: 124, failed: 7, assertions: 976, duration: ~268s
```

All seven failures are in `Tests\Feature\AgentEnrollmentTest` and share one
cause: `server_enrollments.expires_at` and `consumed_at` default to `now()` in
`2026_11_11_000003_create_servers_tables.php`, so every issued token is already
expired and consumed. Representative failure:

```text
Tests\Feature\AgentEnrollmentTest::test_issuing_an_enrollment_token_stores_it_hashed_and_marks_the_server_pending
Failed asserting that ... is null.
```

This is fixed as the first change of Milestone 1.

### Go

```text
go test ./...   all packages pass
go vet ./...    clean
```

Packages with tests: `agent`, `client`, `commands`, `config`, `docker`,
`enrollment`, `identity`, `metrics`. `protocol` and `cmd/cloudforge-agent` have
no test files.

### Frontend and installer

- `npm run build` is available but was not part of the baseline test run.
- `sh -n apps/control-plane/public/install.sh` passes.
- The installer has no automated tests.
- The systemd template in `infrastructure/systemd/cloudforge-agent.service` was
  not compared byte-for-byte with the unit rendered by the installer.

### Not verified in real infrastructure

- Enrollment against a real control-plane deployment over HTTPS.
- Agent operation against a real Docker daemon.
- PostgreSQL/Redis behaviour.
- Certificate rotation over 12-hour intervals.
- Systemd restart, upgrade and uninstall behaviour.
- Three-node acceptance criterion for Phase 1.
