# Implementation progress

**Last updated:** 2026-11-12
**Baseline:** `docs/implementation/REPOSITORY_AUDIT.md`
**Plan:** `docs/implementation/IMPLEMENTATION_PLAN.md`

This file records what is actually verified, not just what is coded. Status terms
follow the plan:

| Status | Meaning |
| --- | --- |
| Not started | No implementation work has begun. |
| In progress | Code exists but acceptance criteria are not met. |
| Implemented | Code and automated tests exist; local tests pass. |
| Tested | Implemented plus negative, idempotency and isolation tests pass. |
| Verified in real infrastructure | Exercised on a real VPS with real Docker, PostgreSQL, DNS and TLS. |

## Verification environment

| Item | Value |
| --- | --- |
| Host | Windows 11, Git Bash for POSIX shell tests |
| PHP | 8.4.23 |
| Laravel | 13.42.0 |
| Go toolchain | go1.27.4 windows/amd64 |
| Node.js / npm | 26.5.0 / 11.17.0 |
| Database under test | SQLite in memory (PHPUnit) |
| Cache, session and queue under test | Array drivers (PHPUnit) |
| Docker under test | Fake Docker HTTP server over a test Unix socket; no real daemon |
| systemd under test | None; no Linux host available |

The local host has no PostgreSQL server, no Docker daemon and no systemd. Results
below must be read with that boundary in mind.

---

## Milestone 0 — repository audit and baseline

**Status:** Implemented.

Evidence:

- `docs/implementation/REPOSITORY_AUDIT.md` records architecture, implemented,
  partial and missing functionality, security findings and technical debt.
- `docs/implementation/IMPLEMENTATION_PLAN.md` records all milestones, their
  dependencies and acceptance criteria.
- Baseline PHP tests failed in seven enrollment cases because the initial
  `servers` migration created duplicate SQLite index names and because the
  enrollment schema and models disagreed on nullable/default columns. The
  baseline failure list is recorded in the audit.
- No production code was changed before the audit was written.

---

## Milestone 1 — self-hosted bootstrap

**Goal:** a clean installation registers its own VPS as the first managed server,
with a real agent identity and real host inventory, without copying a server UUID
or token from the dashboard.

**Status:** Tested locally. Not verified in real infrastructure.

### Requirement evidence

| # | Requirement | Implementation | Automated evidence | Verification level |
| --- | --- | --- | --- | --- |
| 1 | Clean VPS installation registers itself | `infrastructure/scripts/bootstrap-node.sh`, `cloudforge:bootstrap`, `prepareBootstrapServer`, `install.sh` bootstrap mode | `BootstrapCommandTest`, `BootstrapProvisioningTest`, `InstallerScriptTest`, shell harness | Tested locally. No clean VPS run. |
| 2 | No manual dashboard registration | Unclaimed bootstrap server is claimed by organization creation, single-org enrollment, or CLI | `BootstrapWebTest`, `AgentEnrollmentTest` | Tested locally. |
| 3 | Re-running installation does not duplicate the server | Machine-id lookup in `PrepareBootstrapServer`; unique `servers.machine_id`; `already_enrolled` path | `BootstrapProvisioningTest` idempotency cases | Tested locally. |
| 4 | Replacing an agent does not create an uncontrolled identity | Existing identity skip; `CLOUDFORGE_FORCE_ENROLL`; revocation before re-enrollment; certificate uniqueness per server | `AgentEnrollmentTest` rotation and revocation cases; installer harness | Tested locally. No host-level replacement run. |
| 5 | Bootstrap credentials are short-lived | `cloudforge.bootstrap.token_ttl_minutes` default 30; `expires_at` enforced during redemption | `BootstrapProvisioningTest`, `AgentEnrollmentTest` | Tested locally. |
| 6 | Bootstrap credentials are single-use | Transactional redemption with row lock; consuming one token consumes siblings | `AgentEnrollmentTest` replay and concurrency-adjacent cases | Tested locally. |
| 7 | Bootstrap credentials are scoped to the local node | Token belongs to one server; bootstrap redemption requires the recorded `machine_id`; mismatch does not consume the token | `AgentEnrollmentTest` mismatch cases | Tested locally. |
| 8 | Credentials are securely stored | Only SHA-256 hash in `server_enrollments`; credential file mode `0600`; status file excludes token; atomic writes | `BootstrapCredentialTest`, installer harness | Tested locally. Real `0600` assertion skipped on Windows; runs on Linux CI. |
| 9 | Credentials never appear in browser JS or public logs | Status JSON has no token; command output never prints token; onboarding exposes no token data | `BootstrapCommandTest`, `BootstrapWebTest` | Tested locally. |
| 10 | Credential invalidated after enrollment | Token consumed in database; installer deletes credential after the enrollment block | `AgentEnrollmentTest`, `BootstrapCredentialTest`, installer harness | Tested locally. |
| 11 | No arbitrary shell execution | Go executor switches on four typed operations; no shell linked; control plane validates payloads | `AgentCommandTest`, Go `internal/commands` tests | Tested locally. |
| 12 | Agent operations are typed and validated | `AgentOperation::payloadRules()` accepts only optional `reason`; unknown operations fail | `AgentCommandTest`, Go executor tests | Tested locally. |
| 13 | Infrastructure mutations flow through the agent | Milestone 1 exposes inspection and health only; there are no Docker mutations to bypass the agent | Route inventory; `AgentOperation`; Go executor | Tested locally by absence of mutation routes. No mutation feature exists yet. |
| 14 | Tenant isolation on unclaimed servers | `ServerPolicy` denies unclaimed servers to every role; web and API queries are organization-rooted | `RbacTest`, `TenantIsolationTest`, `BootstrapWebTest` | Tested locally. |
| 15 | Audit logging | `server.bootstrap_provisioned`, `server.bootstrap_claimed`, `server.enrolled`; hashed machine-id in metadata; nullable organization supported | `BootstrapProvisioningTest`, `AgentEnrollmentTest`, `BootstrapWebTest` | Tested locally. |
| 16 | Hashed tokens and encrypted secrets | API and invitation tokens hashed; session encryption enabled in production config; CA key stored outside the database | Existing `OrganizationApiTest`, `AgentEnrollmentTest`; config inspection | Implemented and locally tested. PostgreSQL/Redis production behavior unverified. |
| 17 | Async operation references | Durable `agent_commands` with UUIDs and terminal states | `AgentCommandTest` | Implemented. No long-running Milestone 1 mutation exists to exercise. |
| 18 | Onboarding and server UI | Five unclaimed nodes with name, hostname, machine-id prefix, status, enrollment state, token expiry; bootstrap badge, origin and claimed timestamp | `BootstrapWebTest`; `npm run build` | Automated response assertions pass. No real-browser click-through. |
| 19 | Documentation | Architecture, API, agent protocol, installation, deployment, plan and this progress file | Link audit; manual review | Written and locally checked. |
| 20 | Preserve Phase 1 functionality | Full PHP suite, Go suite, shell harness and frontend build | See command evidence | Tested locally. |
| 21 | Three VPS nodes registered | Requires real VPS hosts | None | **Not verified.** No VPS available. |
| 22 | Real Docker inventory | Agent talks to a real Docker daemon | Fake Docker server tests only | **Not verified.** No Docker available. |
| 23 | One-command clean-VPS installation | Root `install.sh` provisions PHP, PostgreSQL, Redis, Nginx, Node, Docker, Composer, TLS, the dashboard, scheduler, queue worker and the source-built agent | `InstallerScriptTest`; `one_command_installer_test.sh`; `installer_test.sh` | Tested locally. No clean VPS run. |
| 24 | PostgreSQL and Redis resource provisioning | `resources` domain, typed `resource.*` agent commands, Docker image pull and container lifecycle | `ResourceProvisioningTest`; Go Docker and executor tests | Tested locally against a fake Docker API. No real Docker run. |
| 25 | Audited database credentials | Encrypted resource password, authorized repeated credential viewing, command-result redaction | `ResourceProvisioningTest` credential and agent-result tests | Tested locally. |
| 26 | Resource lifecycle and recovery | Start, stop, delete, inspect, scheduled reconciliation | `ResourceProvisioningTest`; `ReconcileResources` | Tested locally. |
| 27 | MySQL, application attachment and backups | Not implemented | None | **Not started.** |
| 28 | Product UI shell and design foundation | Theme tokens, collapsible sidebar, breadcrumbs, account menu, shared cards/stats/credentials, responsive drawer | `DatabasesIndex`/dashboard feature tests; `npm run build` | Tested locally. No browser E2E or formal contrast audit. |
| 29 | Organization-wide Databases workspace | Real resource index with search, filters, sorting and lifecycle actions | `ResourceProvisioningTest` | Tested locally. |
| 30 | Projects listing and workspace | Real counts, search/sort, project header, Overview/Databases/Environments/Activity/Settings tabs | `ResourceProvisioningTest`; `npm run build` | Tested locally. No browser E2E. |
| 31 | Registry-blocked image pulls | Local image reuse, mirror-first default pulls, three-minute per-registry timeout, Docker Hub and `public.ecr.aws` fallback, sanitized errors, configurable images | Go Docker tests | Tested locally against fake Docker responses. Real registry reachability unverified. |
| 32 | Stuck resource recovery | Reconcile fails commandless resources, expires the command ledger with the resource, reports waiting operations, bounded scheduler overlap locks | `ResourceProvisioningTest` reconcile tests | Tested locally. |

### Command evidence

| Command | Result |
| --- | --- |
| `vendor/bin/pint --format agent` | Formatted four files; the rerun reports `passed`. |
| `php artisan test --compact` | Final full run: 198 tests passed, 1,544 assertions, 149.7 seconds. |
| `php artisan test --compact tests/Feature/InstallerScriptTest.php tests/Feature/BootstrapCommandTest.php` | 12 tests passed, 57 assertions after the final shell edits. |
| `bash -n install.sh` | Parsed without error. |
| `bash tests/shell/one_command_installer_test.sh install.sh` | 20 assertions, 0 failures. |
| `sh tests/shell/installer_test.sh` | 35 assertions, 0 failures, 2 skipped mode/execute-bit checks on Windows. |
| `php artisan test --compact tests/Feature/ResourceProvisioningTest.php tests/Feature/AgentCommandTest.php` | 28 tests passed, 213 assertions. |
| `php artisan test --compact tests/Feature/ResourceProvisioningTest.php` | 18 tests passed, 199 assertions after the reconciliation changes. |
| `go test ./internal/docker ./internal/commands` | Passed, including managed resource creation, deletion, executor payload tests and mirror-first registry fallback. |
| `go test ./...` | All packages passed; `internal/agent` 4.2s, `internal/client` 6.2s, `internal/enrollment` 6.7s. |
| `go vet ./...` | No findings. |
| `sh -n` on `public/install.sh`, `bootstrap-node.sh`, `installer_test.sh`, `package.sh` | All parsed without error. |
| `npm run build` | Vite 8.3.8 built 617 modules; lazy page chunks, main JS 361.61 kB (gzip 112.96 kB), CSS 87.02 kB (gzip 16.80 kB). |
| `VERSION=1.0.0 sh packaging/package.sh` | Built `cloudforge-agent_linux_amd64.tar.gz`, `cloudforge-agent_linux_arm64.tar.gz` and `SHA256SUMS`. |
| `VERSION=1.2.1 sh packaging/package.sh` | Built the mirror-first agent archives and `SHA256SUMS`. |
| GitHub release `v1.0.0` | Three assets uploaded; the latest download URLs return HTTP 200. |
| GitHub release `v1.1.0` | Managed-resource agent assets uploaded; latest download URLs return HTTP 200. |
| GitHub release `v1.2.0` | Project-workspace and registry-fallback agent assets uploaded; latest download URLs return HTTP 200. |
| GitHub release `v1.2.1` | Mirror-first agent assets uploaded; all three download URLs return HTTP 200. |
| `go test`/`go vet` after machine-id override | Passed. The new override and invalid-value tests are in `internal/enrollment/enrollment_test.go`. |

The final full PHP suite, after the installer, resource layer, project workspace
and UI shell changes, passed with the counts above. The targeted installer,
resource, project and command tests also pass after the last shell and policy
edits.

### Defects found and fixed during Milestone 1 verification

1. **Duplicate SQLite index names** in the initial servers migration blocked
   enrollment tests. Certificate indexes were renamed and the full enrollment
   suite now passes.
2. **Installer checksum parsing did not match the release file format.**
   `SHA256SUMS` can contain `hash *./file`; the installer compared the raw second
   field to the bare archive name and would have failed every real installation.
   `expected_checksum()` now normalizes the optional binary marker and `./`
   prefix, and three harness assertions cover plain, prefixed and missing
   entries.
3. **`CLOUDFORGE_MACHINE_ID` was only wired into the control-plane inspector.**
   The agent still read only `/etc/machine-id`, so the documented override could
   not work end to end. The agent now accepts the override during enrollment,
   normalizes it to lowercase, rejects malformed values, and the installer and
   bootstrap wrapper pass it through.
4. **Documentation overstated Docker inventory.** Early text claimed container,
   image, network and volume counts. The agent only calls `GET /version` and
   `GET /info`; the architecture, agent README, protocol and installation docs
   now state that limit.
5. **The one-command installer omitted the default `.env` path in its
   `set_env_value` calls.** The shell contract harness caught the unbound
   variable before a VPS run; the function now defaults to `ENV_FILE`.

### Not verified in real infrastructure

These are deliberate gaps, not hidden failures:

- No clean Linux VPS installation; `install.sh` and `bootstrap-node.sh` have
  never run as root under systemd.
- No real run of the one-command installer: apt package resolution, PostgreSQL
  provisioning, Let's Encrypt, Docker startup and the on-host Go build are
  unverified.
- No real systemd service start, restart, journal inspection or `agent.restart`
  supervision.
- No PostgreSQL run. All database tests use in-memory SQLite; production
  migrations, locking and JSON behavior on PostgreSQL are unverified.
- No Redis run. Session, cache and queue behavior on Redis is unverified.
- No real Docker daemon. The agent's Docker client is tested against a fake
  server over a Unix socket; real `/version` and `/info` responses, permissions
  and rootless detection are unverified.
- The resource layer has never created a real container. Docker image pull,
  loopback port binding, persistent volumes, credential use and deletion are
  unverified on a VPS.
- The agent executes one command at a time. A slow image pull delays queued
  start, stop and delete commands; the pull is now bounded, but there is no
  concurrent command execution.
- Reachability of `mirror.gcr.io`, Docker Hub and `public.ecr.aws` from the
  target VPS is unverified. The agent logs each registry attempt so the actual
  path can be checked from the journal.
- No MySQL resource, application attachment, backup or restore is implemented.
- No real DNS or TLS. Enrollment over a public HTTPS endpoint, certificate
  chains, reverse-proxy client-certificate forwarding and rate limiting behind a
  proxy are unverified.
- No real browser session. Inertia responses and props are asserted in feature
  tests; no browser click-through or visual review was performed.
- No three-node enrollment; the Phase 1 acceptance criterion remains open.
- Release assets `v1.2.1` are published, but the installer's live download path
  has not been exercised from a VPS.
- `agent.env` mode `0600` could not be confirmed on Windows; the harness skips
  that assertion. Linux CI must run it.

### Milestone 1 conclusion

Code, local automated tests, shell checks and the frontend build are complete.
The milestone is **not** "Verified in real infrastructure". Phase 2 must not
start until the Phase 1 acceptance run is performed on real VPS hosts with real
Docker, PostgreSQL, DNS and TLS.

---

## Later milestones

Milestones 2–8 remain **Not started**. Their prerequisites and acceptance
criteria are in `docs/implementation/IMPLEMENTATION_PLAN.md`. Starting them now
would violate the requirement that Phase 1 acceptance tests pass first.
