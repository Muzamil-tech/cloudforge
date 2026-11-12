# CLOUDFORGE — MASTER IMPLEMENTATION PROMPT

## Transform the Existing Foundation into a Production-Ready, Self-Hosted Application Cloud

**Project:** CloudForge  
**Implementation type:** Existing repository enhancement and production implementation  
**Priority:** Critical  
**Target:** Laravel Cloud-like developer experience on self-owned VPS infrastructure  
**Architecture:** Laravel Control Plane + Go Infrastructure Agent + Docker + BuildKit  
**Development approach:** Repository-first, incremental, test-driven, production-oriented

---

# 1. YOUR ROLE

You are acting as the Principal Software Architect, Senior Laravel Engineer, Senior Go Engineer, DevOps Engineer, Infrastructure Engineer, Security Engineer, and Product Engineer responsible for completing CloudForge.

You are not building a prototype, a landing page, a server-management dashboard, or a collection of UI screens.

You are completing an existing infrastructure platform whose foundational architecture has already been implemented.

You must inspect the repository, understand its existing architecture, identify incomplete functionality, and implement the actual infrastructure operations required to make CloudForge a working application deployment platform.

You must work directly in the existing repository.

Do not replace the architecture without a documented technical reason.

Do not recreate already implemented functionality.

Do not introduce fake functionality to make the dashboard appear complete.

Every user-facing operation must execute a real backend operation and report its actual result.

# 2. PRODUCT OBJECTIVE

CloudForge must provide a self-hosted developer platform similar in experience to Laravel Cloud, running entirely on infrastructure controlled by the CloudForge operator.

A developer should be able to:

1. Install CloudForge on a VPS.
2. Have that VPS automatically detected and registered as the initial CloudForge server.
3. Open the CloudForge dashboard.
4. Connect a GitHub account.
5. Import a repository.
6. Create an application.
7. Have CloudForge automatically detect its framework and runtime.
8. Deploy the application without manually writing a Dockerfile for supported frameworks.
9. Provision PostgreSQL, MySQL, or Redis with one-click operations.
10. Attach databases to applications automatically.
11. Configure domains and HTTPS.
12. Configure application environment variables.
13. Manage workers, schedulers, and other application processes.
14. View live deployment logs, application logs, and infrastructure metrics.
15. Roll back deployments.
16. Back up and restore databases.
17. Add additional VPS servers.
18. Deploy applications and workers across multiple VPS servers.
19. Manage all infrastructure from one dashboard.

The platform must deliver actual functionality, not merely represent these capabilities in the UI.

# 3. EXISTING REPOSITORY — PRESERVE THIS FOUNDATION

The repository already contains the following implementation.

## 3.1 Laravel Control Plane

Location:

apps/control-plane

Technology:

- Laravel 13
- PHP 8.4
- Inertia.js v3
- React 19
- Existing authentication and authorization implementation

Already implemented:

- Authentication.
- Invitation-gated registration.
- First-user bootstrap.
- Organizations.
- Organization memberships.
- RBAC: owner, admin, developer, operator, viewer.
- Projects and environments.
- Dashboard screens.
- Server registration.
- Short-lived, single-use enrollment tokens.
- SHA-256-hashed enrollment tokens.
- Agent certificate authority.
- CSR signing.
- Certificate issuance, rotation, and revocation.
- Agent request verification.
- Certificate fingerprint verification.
- RSA-SHA256 request signatures.
- Timestamp skew validation.
- Nonce replay protection.
- Optional forwarded client-certificate header verification.
- Heartbeats.
- Server inventory.
- Docker capability storage.
- Metrics recording.
- Node health states and recovery.
- Persisted agent commands.
- Acknowledgement, redelivery, expiry, cancellation, and idempotency.
- Audit logging.
- Organization-scoped `/api/v1` API.
- Bearer tokens using the `cf_` prefix and token abilities.
- Scheduled commands for unreachable servers, metric pruning, and failed-queue pruning.

## 3.2 Go Agent

Location:

apps/agent

Technology:

- Go
- Standard library only in the current implementation.

Already implemented:

- Enrollment.
- Signed agent requests.
- Identity persistence.
- Certificate rotation checks.
- Heartbeats.
- Inventory reporting.
- Metrics reporting.
- Command long-polling.
- Durable command deduplication.
- Restart-after-report semantics.
- Read-only Docker inspection through a Unix socket.
- Rejection of TCP, SSH, and named-pipe Docker endpoints.
- Restricted command allowlist.
- Static Linux AMD64 and ARM64 builds.
- Systemd service.
- Checksums.
- Installer with checksum verification.
- Unprivileged enrollment.

Current allowed commands:

```text
server.inspect
server.health
metrics.collect
agent.restart
```

## 3.3 Existing documentation and infrastructure

Preserve and update:

```text
docs/architecture/overview.md
docs/architecture/decisions/
docs/api/README.md
infrastructure/systemd/cloudforge-agent.service
apps/agent/README.md
```

The repository also contains ADRs 0001–0004.

Read these documents before changing architecture.

## 3.4 Critical implementation constraint

The current Go agent is intentionally read-only.

You must extend it to support controlled infrastructure mutations.

Do not solve this by introducing arbitrary shell execution.

The new command execution architecture must preserve the existing security model.

# 4. MANDATORY FIRST STEP — REPOSITORY AUDIT

Before implementation, inspect the complete repository.

Inspect:

- Root structure and workspace configuration.
- Laravel routes.
- Controllers.
- Models.
- Migrations.
- Services.
- Jobs.
- Events and listeners.
- Policies and middleware.
- Existing API contracts.
- Frontend components and pages.
- Agent source code.
- Agent command protocol.
- Agent persistence.
- Installation scripts.
- Docker configuration.
- Systemd configuration.
- Tests.
- Documentation.
- Existing deployment scripts.

Create:

`docs/implementation/REPOSITORY_AUDIT.md`

Include:

1. Existing architecture.
2. Implemented functionality.
3. Partially implemented functionality.
4. Missing functionality.
5. Existing technical debt.
6. Security risks.
7. Architectural conflicts.
8. Database schema gaps.
9. Frontend/backend integration gaps.
10. Recommended implementation order.

For every important feature, identify actual source files and tests.

Do not classify a feature as implemented merely because a model, route, controller, page, or button exists.

A feature is implemented only when its complete workflow operates successfully.

After the audit, create:

`docs/implementation/IMPLEMENTATION_PLAN.md`

Break the work into executable milestones with dependencies and acceptance criteria.

Do not stop after writing the audit. Continue implementing the plan.

# 5. ARCHITECTURE

Preserve the existing architecture.

## 5.1 Control Plane

Laravel remains responsible for:

- Authentication.
- Organizations and RBAC.
- Applications and projects.
- GitHub integration.
- Deployment orchestration.
- Build orchestration.
- Database provisioning requests.
- Domains and networking configuration.
- Secrets.
- Operations and audit records.
- Resource scheduling.
- Server management.
- Billing-ready resource accounting.

Laravel must not directly execute arbitrary infrastructure commands.

All infrastructure mutations must go through the authorized agent command protocol or an explicitly isolated infrastructure service.

## 5.2 Infrastructure Agent

Go remains responsible for:

- Server discovery.
- Docker Engine interaction.
- Application containers.
- Images.
- Volumes.
- Networks.
- Runtime process management.
- Database instance management.
- Metrics.
- Logs.
- Health checks.
- Deployment activation.
- Rollback execution.
- Infrastructure command reporting.

Use typed operation handlers.

Do not create a general-purpose shell command endpoint.

## 5.3 Infrastructure Components

Use these components where appropriate:

| Component     | Responsibility                    |
| ------------- | --------------------------------- |
| Docker Engine | Container lifecycle               |
| BuildKit      | Application image builds          |
| PostgreSQL    | CloudForge control-plane database |
| Redis         | Cache, queues and event transport |
| Caddy         | TLS and application routing       |
| WireGuard     | Private server networking         |
| Prometheus    | Metrics                           |
| Grafana Loki  | Log aggregation                   |
| OpenTelemetry | Distributed tracing               |

Do not introduce all components merely to complete an architecture diagram.

Integrate them only when their associated workflow is implemented and tested.

# 6. PHASE ONE — AUTOMATIC SELF-HOSTED SERVER REGISTRATION

This is the first critical correction.

When CloudForge is installed on a VPS, that VPS must automatically become the first managed server.

The operator must not need to open the dashboard and manually add the VPS hosting CloudForge.

## 6.1 Required behavior

Installation must:

1. Detect the operating system.
2. Detect CPU architecture.
3. Detect hostname.
4. Discover CPU count.
5. Discover total memory.
6. Discover filesystem capacity and available storage.
7. Discover network interfaces and usable addresses.
8. Detect Docker Engine.
9. Detect Docker version and capabilities.
10. Install or initialize the CloudForge agent.
11. Establish agent identity.
12. Complete secure enrollment.
13. Register the server in the control plane.
14. Report initial inventory.
15. Report initial health.
16. Mark the node ready when all required checks pass.

## 6.2 Bootstrap design

Implement a dedicated installation bootstrap flow.

Conceptual process:

```text
CloudForge installation
        |
        v
Detect local VPS
        |
        v
Initialize control plane
        |
        v
Generate local bootstrap credential
        |
        v
Install CloudForge agent
        |
        v
Secure local enrollment
        |
        v
Issue agent certificate
        |
        v
Register local server
        |
        v
Report inventory
        |
        v
Verify Docker and networking
        |
        v
Mark initial server ready
```

The bootstrap credential must:

- Be short-lived.
- Be single-use.
- Be stored securely.
- Never be written to public logs.
- Be invalidated after enrollment.
- Be scoped exclusively to the initial local node.

Do not weaken the existing certificate authority.

Do not expose the enrollment secret in browser JavaScript.

Do not trust a user-supplied server ID as proof of local identity.

## 6.3 Additional VPS enrollment

The dashboard must separately support adding other servers.

The user should be able to:

1. Open Servers.
2. Click Add Server.
3. Generate a short-lived enrollment command.
4. Run the command on the new VPS.
5. See that VPS appear automatically.
6. Review discovered capabilities.
7. Assign node roles.
8. Mark the node available for workloads.

The enrollment token must be revocable and single-use.

## 6.4 Acceptance criteria

- Clean VPS installation automatically registers itself.
- No manual registration is required for the initial VPS.
- Re-running installation does not create duplicate server records.
- Replacing an agent does not create uncontrolled duplicate identities.
- Agent certificate rotation continues to work.
- Failed bootstrap operations can be recovered safely.

# 7. PHASE TWO — APPLICATION MANAGEMENT ENGINE

Build the actual application deployment platform.

## 7.1 Application data model

Inspect the existing schema and add the missing entities.

At minimum, the application domain must support:

- Applications.
- Application environments.
- Application processes.
- Application instances.
- Application deployments.
- Deployment steps.
- Deployment releases.
- Build records.
- Build artifacts.
- Git repositories.
- Git connections.
- Repository webhooks.
- Environment variables.
- Application domains.
- Runtime configurations.
- Deployment configurations.
- Deployment health checks.

Use UUIDs where consistent with the existing architecture.

All organization-owned resources must have strict organization scoping.

Create proper indexes, foreign keys, unique constraints and deletion behavior.

Do not duplicate existing tables.

## 7.2 Application creation interface

Create a fully functional application creation wizard.

Step 1 — Source:

- Connect GitHub.
- Select repository.
- Select branch.
- Configure automatic deployment.
- Select commit or deployment source.

Step 2 — Runtime:

- Automatically detect framework.
- Display detected language and runtime.
- Allow manual runtime override.
- Configure build settings.
- Configure start command where needed.

Step 3 — Infrastructure:

- Select project.
- Select environment.
- Select deployment node or node group.
- Configure resource limits.

Step 4 — Configuration:

- Configure environment variables.
- Configure build variables.
- Configure deployment settings.

Step 5 — Review:

- Show the complete configuration.
- Validate required fields.
- Create the application.
- Start the first deployment.

Every wizard step must persist and validate actual data.

## 7.3 GitHub integration

Implement GitHub App integration.

Requirements:

- Secure installation flow.
- Repository authorization.
- Repository listing.
- Branch listing.
- Webhook registration.
- Webhook signature validation.
- Commit metadata.
- Pull request metadata where useful.
- Deployment triggering.
- Automatic deployment on configured branch changes.
- Webhook deduplication.
- Webhook retry handling.

Do not ask users to paste personal GitHub tokens as the primary integration.

Use installation-scoped access tokens.

Never log GitHub secrets.

## 7.4 Framework detection

Implement a framework detector.

Initial support:

### Laravel

Detect:

- composer.json.
- composer.lock.
- artisan.
- Laravel framework dependencies.
- PHP version requirements.
- Required PHP extensions.
- package.json.
- Vite.
- Frontend build requirements.

### Node.js

Detect:

- package.json.
- Lock files.
- Node engine requirements.
- Available scripts.
- Framework dependencies.

Support:

- Next.js.
- Nuxt.
- Express.
- NestJS.
- Standard Node.js applications.

### Static applications

Detect supported static build projects and output directories.

### Docker

Support repositories containing a Dockerfile.

Framework detection must produce a structured result, including confidence and detected configuration.

Do not execute arbitrary repository code during detection.

# 8. PHASE THREE — BUILD ENGINE

Integrate BuildKit as the actual image-building system.

Do not simulate builds by generating successful build records.

## 8.1 Build architecture

Build flow:

```text
GitHub webhook
      |
      v
Create build operation
      |
      v
Retrieve authorized source
      |
      v
Create isolated build context
      |
      v
Framework detection
      |
      v
Select build strategy
      |
      v
BuildKit image build
      |
      v
Push/store immutable image
      |
      v
Record image digest
      |
      v
Create deployment release
```

## 8.2 Build requirements

- Immutable image references.
- Build cache.
- Build cancellation.
- Build timeout.
- Build resource limits.
- Streaming build logs.
- Build status tracking.
- Failed build diagnostics.
- Secure build secrets.
- Build artifact metadata.
- Image digest verification.
- Build history.

Never embed secrets in image layers.

Never run untrusted build workloads on the control plane.

Use isolated build workers.

The Docker daemon and BuildKit endpoint must never be exposed publicly.

## 8.3 Laravel build preset

Provide a maintained Laravel build strategy.

Handle:

- Composer dependencies.
- PHP extensions.
- Frontend dependencies.
- Vite builds.
- Production optimization.
- Runtime permissions.
- Application startup.

Do not hardcode one PHP version.

Respect project requirements where supported.

Do not generate a new APP_KEY every deployment.

Do not run migrations during image building.

Keep runtime secrets separate from build-time secrets.

# 9. PHASE FOUR — DEPLOYMENT ENGINE

This is the core CloudForge functionality.

## 9.1 Deployment lifecycle

Implement explicit deployment states:

```text
pending
queued
preparing
building
built
deploying
starting
health_checking
activating
succeeded
failed
cancelled
rolling_back
rolled_back
```

Use transactional state transitions.

Every deployment must have:

- A unique deployment ID.
- Application ID.
- Environment ID.
- Git commit.
- Build ID.
- Image digest.
- Deployment configuration snapshot.
- Deployment steps.
- Start and finish timestamps.
- Failure reason.
- Logs.
- Release reference.

## 9.2 Deployment execution

Implement:

1. Create deployment.
2. Resolve source commit.
3. Build immutable image.
4. Verify artifact.
5. Select target node.
6. Reserve resources.
7. Prepare the new release.
8. Inject runtime configuration securely.
9. Start new containers.
10. Run health checks.
11. Activate traffic.
12. Drain old containers.
13. Record successful release.
14. Preserve rollback information.

Use blue-green deployment when capacity permits.

Provide controlled rolling or immediate deployment modes where appropriate.

Do not switch production traffic before health checks pass.

## 9.3 Rollback

Implement real rollback.

A rollback must:

- Resolve the previous successful release.
- Validate its image availability.
- Validate runtime configuration compatibility.
- Prepare previous containers.
- Run health checks.
- Restore traffic.
- Record rollback history.

Never automatically reverse database migrations.

Use expand-and-contract migration guidance.

# 10. PHASE FIVE — DATABASE PLATFORM

This is a critical product requirement.

Users must be able to provision and attach actual databases directly from the application dashboard.

A database record without a working database is not a successful implementation.

## 10.1 Supported engines

Initial supported engines:

- PostgreSQL.
- MySQL.
- Redis.

Support two provisioning models:

### Shared database clusters

Multiple applications use isolated databases and users within a managed cluster.

Requirements:

- Per-application database.
- Per-application credentials.
- Least-privilege database user.
- Private network access.
- Quota management.
- Resource accounting.
- Cluster health.
- Backup policy.

### Dedicated database instances

An isolated database instance or container with dedicated resources.

Requirements:

- Version selection.
- CPU allocation.
- Memory allocation.
- Persistent volume.
- Storage allocation.
- Private networking.
- Health monitoring.
- Backup configuration.

Do not describe a shared database as dedicated.

## 10.2 One-click database creation

Create a functional database provisioning wizard.

User workflow:

1. Open application.
2. Open Databases tab.
3. Click Attach Database.
4. Select PostgreSQL, MySQL or Redis.
5. Select shared or dedicated.
6. Select version.
7. Select placement.
8. Configure resources if dedicated.
9. Click Create and Attach.

CloudForge must then:

1. Validate organization permissions.
2. Validate available resources.
3. Select an appropriate node or cluster.
4. Allocate persistent storage if required.
5. Provision the actual database.
6. Create the database and user.
7. Generate secure credentials.
8. Configure private network access.
9. Test the connection.
10. Store credentials encrypted.
11. Create the database attachment.
12. Configure application environment variables.
13. Restart or redeploy as required.
14. Report the actual result.

The operation must be asynchronous and observable.

Display real provisioning stages.

## 10.3 Laravel environment configuration

PostgreSQL:

```text
DB_CONNECTION=pgsql
DB_HOST=<private-host>
DB_PORT=5432
DB_DATABASE=<database>
DB_USERNAME=<username>
DB_PASSWORD=<secret>
```

MySQL:

```text
DB_CONNECTION=mysql
DB_HOST=<private-host>
DB_PORT=3306
DB_DATABASE=<database>
DB_USERNAME=<username>
DB_PASSWORD=<secret>
```

Redis:

```text
REDIS_HOST=<private-host>
REDIS_PORT=6379
REDIS_PASSWORD=<secret>
```

Do not expose secrets through API responses after initial creation.

Provide secure credential rotation.

## 10.4 Database lifecycle

Implement:

- Provision.
- Start.
- Stop.
- Restart.
- Inspect.
- Monitor.
- Backup.
- Restore.
- Rotate credentials.
- Delete.

Deletion must require explicit confirmation and account for attached applications and backups.

## 10.5 Backups

Implement actual backups.

Requirements:

- Scheduled backups.
- Manual backups.
- Encrypted backup artifacts.
- Off-node storage.
- Configurable retention.
- Backup verification.
- Restore to a new instance.
- Restore status.
- Backup history.
- Failed backup alerts.

Support point-in-time recovery only when the chosen engine and backup architecture genuinely support it.

# 11. PHASE SIX — APPLICATION RUNTIME MANAGEMENT

Implement Laravel-specific runtime management.

Support independent process groups.

## 11.1 Web process

Support:

- PHP-FPM.
- Nginx integration.
- Laravel application startup.
- Configurable resource limits.
- Health checks.

## 11.2 Octane

Support Laravel Octane where the project requires it.

Support appropriate runtimes such as:

- FrankenPHP.
- Swoole.
- RoadRunner.

Do not assume every Laravel application requires Octane.

## 11.3 Queue workers

Support:

- Standard Laravel queue workers.
- Configurable queue names.
- Worker count.
- Timeout.
- Memory limits.
- Restart.
- Graceful termination.
- Queue health.

## 11.4 Horizon

Detect and support Laravel Horizon.

Provide:

- Status.
- Worker configuration.
- Queue monitoring.
- Restart.
- Scaling controls.

## 11.5 Scheduler

Implement Laravel scheduler using a controlled scheduler service.

Requirements:

- Distributed locking.
- Single active scheduler execution per application environment.
- Missed-run handling where appropriate.
- Execution history.
- Logs.
- Health reporting.

Do not create duplicate scheduler execution across multiple replicas.

## 11.6 Reverb

Support Laravel Reverb as an independently managed process group.

Provide:

- Process configuration.
- Environment variables.
- Health checks.
- Logs.
- Restart.
- Domain and WebSocket routing.

# 12. PHASE SEVEN — DOMAINS AND NETWORKING

Implement actual application routing.

Use Caddy as the initial reverse proxy and TLS component, unless repository inspection identifies an existing working alternative.

Requirements:

- Automatic application subdomains.
- Custom domains.
- Domain verification.
- DNS guidance.
- TLS certificate provisioning.
- Automatic certificate renewal.
- HTTPS enforcement.
- Reverse proxy configuration.
- WebSocket support.
- Health-aware routing.
- Safe configuration reloads.

Do not report HTTPS as active before certificate issuance and verification.

Do not expose database ports publicly by default.

Use private networking between application and database workloads.

Support WireGuard for communication across VPS nodes.

# 13. PHASE EIGHT — REAL-TIME EXPERIENCE

The dashboard must update without page refreshes.

Use Laravel Reverb and WebSockets for browser event delivery.

Use Redis-backed event transport where appropriate.

Implement events for:

- Deployment state changes.
- Build output.
- Deployment logs.
- Server health.
- Database provisioning.
- Database health.
- Application status.
- Worker status.
- Scaling operations.
- Backup and restore operations.

Requirements:

- Organization-authorized private channels.
- Reconnection handling.
- Event persistence.
- Recovery of missed events.
- Event ordering where required.
- Backpressure.
- Log pagination.
- Rate limiting.

Do not use WebSockets as the authoritative source of operation state.

Persist operations and events so the browser can recover after disconnection.

Metrics should use controlled sampling intervals rather than publishing every metric sample as an unrestricted WebSocket event.

# 14. PHASE NINE — MULTI-VPS ORCHESTRATION

CloudForge must support multiple servers from the beginning of the product architecture.

A single VPS installation is the first deployment topology, not the final platform architecture.

## 14.1 Node roles

Support:

- Control.
- Application.
- Build.
- Database.
- Edge.
- Storage.
- General.

Allow a server to have multiple roles when safe and appropriate.

## 14.2 Resource scheduling

Implement resource-aware placement.

Consider:

- CPU capacity.
- Memory capacity.
- Disk capacity.
- Architecture.
- Server health.
- Node roles.
- Resource reservations.
- Workload constraints.
- Organization placement rules.

Resource reservation must be atomic.

Prevent over-allocation based on stale metrics.

## 14.3 Scaling

Distinguish:

1. Multiple containers on one VPS.
2. Application replicas across multiple VPS servers.
3. Worker scaling.
4. Database capacity management.
5. Provisioning additional VPS infrastructure.

Do not claim automatic VPS provisioning unless a real cloud-provider integration exists.

Do not automatically move stateful databases without a defined replication and recovery procedure.

## 14.4 Node draining

Implement:

- Stop new scheduling.
- Identify active workloads.
- Migrate or stop stateless workloads safely.
- Preserve stateful workloads unless a safe migration exists.
- Report draining progress.
- Complete maintenance transition.

# 15. PHASE TEN — FRONTEND AND PRODUCT EXPERIENCE

The frontend must feel like a professional developer cloud platform.

Do not make the dashboard a server-management panel with decorative application cards.

## 15.1 Main navigation

Implement:

- Overview.
- Applications.
- Projects.
- Databases.
- Servers.
- Builds.
- Deployments.
- Storage.
- Domains.
- Networking.
- Monitoring.
- Logs.
- Backups.
- Team members.
- API tokens.
- Audit logs.
- Settings.

Only display features that have actual functional implementation.

For incomplete features, use an honest disabled or clearly marked planned state. Do not display fake operational statuses.

## 15.2 Application detail navigation

Every application must have:

- Overview.
- Deployments.
- Runtime.
- Processes.
- Domains.
- Environment Variables.
- Databases.
- Logs.
- Metrics.
- Settings.

## 15.3 Application overview

Show real:

- Application status.
- Deployment status.
- Current release.
- Git branch.
- Latest commit.
- Deployment time.
- Runtime.
- Server placement.
- CPU usage.
- Memory usage.
- Network metrics.
- Domain status.
- Attached databases.

## 15.4 Database interface

Show:

- Engine.
- Version.
- Status.
- Provisioning model.
- Server placement.
- Storage.
- Resource usage.
- Backup status.
- Attached applications.
- Connection details with secrets masked.

Provide real actions:

- Create.
- Attach.
- Detach.
- Restart.
- Backup.
- Restore.
- Rotate credentials.
- Delete.

## 15.5 Deployment interface

Show:

- Deployment history.
- Commit information.
- Build progress.
- Deployment stages.
- Streaming logs.
- Health-check results.
- Failed step diagnostics.
- Rollback action.

A deployment page must display actual deployment state from the backend.

## 15.6 UI quality

Use the existing design system and frontend conventions.

Requirements:

- Professional infrastructure dashboard.
- Responsive layout.
- Accessible components.
- Consistent spacing.
- Loading states.
- Empty states.
- Error states.
- Confirmation dialogs for destructive actions.
- Toast feedback.
- Real-time updates.
- Proper pagination.
- Search and filtering.

Do not replace existing working screens without a demonstrated reason.

# 16. DATA MODEL AND OPERATION DESIGN

Before adding migrations, inspect the existing database schema.

Extend the existing models rather than duplicating them.

Add the missing resource models needed for:

- Applications.
- Processes.
- Builds.
- Releases.
- Deployments.
- Domains.
- Environment variables.
- Git connections.
- Repository webhooks.
- Database clusters.
- Database instances.
- Databases.
- Database attachments.
- Database backups.
- Database restores.
- Volumes.
- Networks.
- Resource reservations.
- Provisioning operations.
- Runtime configurations.

Use explicit state machines for long-running operations.

Every infrastructure operation must have:

- Operation ID.
- Organization ID.
- Resource reference.
- Initiating user.
- Operation type.
- Idempotency key.
- Status.
- Progress.
- Current step.
- Start timestamp.
- Completion timestamp.
- Failure details.
- Retry policy.
- Audit record.

All asynchronous operations must be recoverable after process restarts.

Do not rely on in-memory state.

Use database transactions for control-plane state changes.

Do not hold database transactions open during long-running infrastructure operations.

Use an outbox pattern or equivalent reliable event-publication strategy where needed.

# 17. AGENT PROTOCOL EXTENSION

Extend the existing agent command protocol.

Do not create a separate insecure communication mechanism.

Every command must have:

- Protocol version.
- Command ID.
- Server ID.
- Operation name.
- Issued timestamp.
- Expiration timestamp.
- Idempotency key.
- Typed payload.
- Signature.
- Expected execution timeout.

The agent must:

1. Verify command authenticity.
2. Validate the protocol version.
3. Validate command expiry.
4. Validate operation allowlist.
5. Validate the payload schema.
6. Check durable deduplication.
7. Persist execution state.
8. Execute the typed handler.
9. Persist the result.
10. Report acknowledgement and result.
11. Recover unfinished operations after restart.

Define separate command states:

```text
queued
delivered
acknowledged
running
succeeded
failed
expired
cancelled
```

The control plane must reconcile command state with actual infrastructure state.

Do not blindly retry destructive or stateful operations.

Implement bounded concurrency and resource-aware execution.

# 18. SECURITY REQUIREMENTS

Security is mandatory.

## 18.1 Authentication and authorization

- Enforce organization isolation.
- Enforce RBAC on every mutation.
- Validate ownership of every referenced resource.
- Prevent cross-organization resource access.
- Scope API tokens.
- Hash API tokens.
- Support revocation.
- Audit privileged operations.

## 18.2 Agent security

- Preserve mTLS.
- Preserve certificate rotation.
- Preserve revocation.
- Validate signatures.
- Validate timestamp skew.
- Prevent replay attacks.
- Do not expose Docker TCP APIs.
- Do not expose the Docker socket to customer applications.
- Restrict agent execution privileges.

## 18.3 Container security

- Run application containers as non-root where possible.
- Drop unnecessary Linux capabilities.
- Avoid privileged containers.
- Apply CPU and memory limits.
- Apply PID limits.
- Use seccomp.
- Restrict filesystem mounts.
- Restrict access to host resources.
- Separate build workers from production workloads.
- Protect build secrets.
- Isolate tenant workloads.

## 18.4 Secrets

- Encrypt secrets at rest.
- Keep encryption master keys outside the database.
- Use per-organization data encryption keys where practical.
- Support key rotation.
- Never expose plaintext secrets in logs.
- Never return stored secret values in normal API responses.
- Use short-lived credentials for external integrations.

## 18.5 Networking

- Keep database ports private.
- Restrict management ports.
- Use WireGuard for private node communication.
- Validate all routing configurations.
- Protect against SSRF.
- Restrict outbound network access for untrusted build processes where possible.

# 19. OBSERVABILITY AND RECOVERY

Implement actual infrastructure observability.

Monitor:

- Control-plane health.
- Queue health.
- Agent connectivity.
- Server CPU.
- Server memory.
- Disk utilization.
- Container health.
- Application health.
- Build execution.
- Deployment success.
- Database availability.
- Database storage.
- Backup success.
- Network utilization.

Support:

- Structured logs.
- Correlation IDs.
- Distributed traces where appropriate.
- Error reporting.
- Operation history.
- Failure diagnostics.

Implement:

- Control-plane database backups.
- Off-node backups.
- Agent recovery.
- Queue recovery.
- Deployment recovery.
- Database restore procedures.
- Disaster recovery documentation.

Do not claim high availability without testing the actual failure scenarios.

# 20. API DESIGN

Use the existing `/api/v1` convention.

Create consistent API resources for:

- Applications.
- Git connections.
- Repositories.
- Builds.
- Deployments.
- Releases.
- Processes.
- Domains.
- Environment variables.
- Databases.
- Database attachments.
- Backups.
- Restores.
- Networks.
- Volumes.
- Operations.
- Metrics.
- Logs.

All long-running operations must return an asynchronous operation reference.

Example response:

```json
{
  "data": {
    "operation_id": "uuid",
    "resource_id": "uuid",
    "status": "queued",
    "message": "Database provisioning started"
  }
}
```

Use:

- Consistent validation errors.
- Consistent authorization errors.
- Pagination.
- Filtering.
- Idempotency keys.
- Request correlation IDs.
- Rate limiting.

Document all endpoints.

# 21. TESTING REQUIREMENTS

Do not rely on successful frontend rendering.

Write real tests.

## 21.1 Laravel tests

Test:

- Authentication.
- Organization isolation.
- RBAC.
- Enrollment.
- Certificate issuance.
- Certificate rotation.
- Signature verification.
- Replay protection.
- Heartbeats.
- Agent commands.
- Command expiry.
- Command idempotency.
- GitHub webhooks.
- Application creation.
- Framework detection.
- Build lifecycle.
- Deployment lifecycle.
- Database provisioning.
- Database attachment.
- Secret handling.
- Rollback.
- Backup and restoration.
- Domain provisioning.

## 21.2 Go tests

Test:

- Agent enrollment.
- Signature generation.
- Certificate rotation.
- Inventory.
- Metrics.
- Command validation.
- Durable deduplication.
- Command expiry.
- Agent restart recovery.
- Docker handler security.
- Container lifecycle handlers.
- Database lifecycle handlers.
- Failure reporting.

## 21.3 Integration tests

Run actual integration tests using isolated test infrastructure.

At minimum:

1. Register a real agent.
2. Create a test application.
3. Build an image.
4. Start a container.
5. Verify HTTP health.
6. Provision a database.
7. Connect the application to it.
8. Verify actual database queries.
9. Deploy a new release.
10. Roll back.
11. Verify the previous release is serving traffic.
12. Create a backup.
13. Restore it.
14. Verify the restored data.

Mock external services only when testing isolated failure scenarios. Do not substitute mocks for the end-to-end acceptance tests.

# 22. DEVELOPMENT EXECUTION RULES

These rules are mandatory.

## Rule 1 — Inspect before editing

Never assume existing code is missing without checking.

## Rule 2 — Preserve working functionality

Do not replace working authentication, enrollment, certificate management, agent communication, or existing authorization.

## Rule 3 — No fake features

A button must trigger a real operation.

A status must reflect actual infrastructure state.

A database must actually exist.

A deployment must actually run.

A domain must actually resolve and serve the application.

A backup must actually be restorable.

## Rule 4 — No silent failures

Return structured errors.

Record failure reasons.

Expose useful diagnostics to authorized users.

## Rule 5 — No arbitrary shell execution

All agent operations must use typed, validated handlers.

## Rule 6 — No premature abstraction

Build reusable components when actual repeated requirements exist.

Do not create a generic plugin framework or complex workflow engine merely to make the architecture appear sophisticated.

## Rule 7 — No architecture rewrites without evidence

Document why a change is necessary.

Update the relevant ADR.

## Rule 8 — Work incrementally

Implement one complete vertical workflow at a time.

Do not build 20 incomplete features simultaneously.

## Rule 9 — Run tests after each milestone

Do not accumulate untested changes.

## Rule 10 — Maintain documentation

Update the architecture, API documentation, setup instructions, and operations documentation with each completed milestone.

# 23. REQUIRED IMPLEMENTATION ORDER

Follow this exact dependency-aware order.

**Milestone 0 — Audit**

- Inspect repository.
- Document architecture.
- Identify implementation gaps.
- Establish baseline test results.

**Milestone 1 — Self-hosted bootstrap**

- Detect local VPS.
- Initialize local agent.
- Automatically enroll local server.
- Verify Docker.
- Display real host capabilities.

**Milestone 2 — Application deployment**

- Application schema.
- GitHub integration.
- Repository import.
- Framework detection.
- BuildKit integration.
- Laravel image build.
- Application container lifecycle.
- Health checks.
- Deployment history.
- Rollback.

**Milestone 3 — Database platform**

- PostgreSQL.
- MySQL.
- Redis.
- Shared clusters.
- Dedicated instances.
- One-click provisioning.
- Application attachment.
- Credentials.
- Private networking.

**Milestone 4 — Laravel runtime**

- Web processes.
- Workers.
- Horizon.
- Scheduler.
- Octane.
- Reverb.
- Environment variables.

**Milestone 5 — Domains and TLS**

- Application domains.
- DNS verification.
- Reverse proxy.
- Automatic HTTPS.
- WebSocket routing.

**Milestone 6 — Real-time dashboard**

- WebSocket events.
- Deployment streaming.
- Logs.
- Metrics.
- Operation progress.
- Server status.

**Milestone 7 — Multi-server orchestration**

- Node groups.
- Placement.
- Resource reservation.
- Cross-server deployment.
- Worker scaling.
- Node draining.

**Milestone 8 — Production hardening**

- Security tests.
- Load tests.
- Backup and restore.
- Failure recovery.
- Monitoring.
- Documentation.
- Installation verification.

# 24. FINAL DEFINITION OF DONE

CloudForge is not considered complete until the following scenario works:

A developer installs CloudForge on a clean Linux VPS.

CloudForge automatically detects and registers that VPS.

The developer opens the dashboard.

The server appears as ready.

The developer connects GitHub.

The developer imports a Laravel application.

CloudForge detects the Laravel framework.

The developer clicks Deploy.

CloudForge:

- Retrieves the source.
- Builds a real image.
- Stores the immutable image reference.
- Starts the application container.
- Configures runtime environment.
- Runs health checks.
- Configures application routing.
- Makes the application accessible over HTTPS.

The developer then opens the application's Databases tab.

They click Attach Database.

They select PostgreSQL.

They click Create and Attach.

CloudForge:

- Provisions a real PostgreSQL database.
- Creates a real database user.
- Generates credentials.
- Establishes private networking.
- Verifies connectivity.
- Stores credentials securely.
- Configures Laravel environment variables.
- Restarts or redeploys the application.
- Verifies the application can execute real database queries.

The developer then:

- Views live deployment logs.
- Views application logs.
- Configures a queue worker.
- Configures the Laravel scheduler.
- Deploys another Git commit.
- Rolls back to the previous release.
- Creates a database backup.
- Restores the backup.

Finally, the operator adds a second VPS and successfully deploys another application or worker to it.

All of these actions must work through the CloudForge dashboard.

No manual Docker commands should be required for normal application management.

No manually created database should be required for the database attachment workflow.

No fake operation statuses are permitted.

# 25. REQUIRED FINAL REPORT

At the end of each milestone, provide:

1. What was implemented.
2. Files created.
3. Files modified.
4. Database migrations added.
5. API endpoints added.
6. Agent commands added.
7. Frontend workflows completed.
8. Tests executed.
9. Test results.
10. Security considerations.
11. Infrastructure requirements.
12. Remaining limitations.
13. Exact instructions for running and verifying the feature.

Maintain:

`docs/implementation/PROGRESS.md`

Track each requirement as:

- Not started.
- In progress.
- Implemented.
- Tested.
- Verified in real infrastructure.

Never mark something verified simply because its code was written.

# 26. YOUR IMMEDIATE TASK

Start by auditing the repository.

Then implement Milestone 0 and Milestone 1 completely.

After that, continue with Milestone 2.

Do not stop after creating a plan.

Do not ask the user to manually describe code already available in the repository.

If an implementation detail is genuinely blocked by missing credentials, external infrastructure, or a required product decision, identify the exact blocker and continue with all independent work.

Your first major deliverable is:

**CloudForge installed on its own VPS, automatically registered as its first managed server, with a working infrastructure agent and a dashboard displaying verified live infrastructure state.**

Your second major deliverable is:

**A real GitHub-to-production Laravel deployment workflow.**

Your third major deliverable is:

**One-click real PostgreSQL provisioning and application attachment.**

Build the product, not a representation of the product.
