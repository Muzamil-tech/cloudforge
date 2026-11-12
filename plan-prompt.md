# CloudForge

## Production Technical Specification & Implementation Blueprint

**Version:** 1.0  
**Architecture:** Multi-tenant, multi-server, self-hosted PaaS  
**Primary goal:** Laravel Cloud-like deployment and infrastructure management on customer-owned VPS infrastructure.

---

# 1. Product definition

CloudForge is a self-hosted Platform as a Service (PaaS) that provides a unified interface for deploying, operating, scaling and monitoring applications across multiple VPS servers.

Users should be able to:

1. Create an organization and invite team members.
2. Register multiple VPS servers.
3. Connect a GitHub account.
4. Create applications from Git repositories.
5. Automatically detect application frameworks.
6. Deploy applications with minimal configuration.
7. Provision PostgreSQL, MySQL and Redis with one click.
8. Attach resources to applications.
9. Automatically configure credentials and networking.
10. Configure domains and HTTPS.
11. Manage background workers and scheduled jobs.
12. Monitor deployments, logs, metrics and resource usage.
13. Scale application replicas across available servers.
14. Roll back deployments.
15. Back up and restore databases.
16. Manage development, staging and production environments.

## 1.1 Product principles

- Developer experience first.
- Infrastructure abstraction.
- Immutable application releases.
- Infrastructure operations must be asynchronous.
- Every operation must be auditable.
- Every resource must have a lifecycle.
- No direct public access to database servers.
- No Docker socket exposed to the internet.
- Control plane and execution plane must be independently deployable.
- Organizations must be isolated from each other.
- Infrastructure must remain recoverable after control-plane or agent failures.

## 1.2 Product boundaries

CloudForge is not initially:

- A Kubernetes distribution.
- A public cloud provider.
- A replacement for PostgreSQL or MySQL.
- A general-purpose virtual machine hypervisor.
- A managed DNS registrar.
- A serverless computing platform.

It is a PaaS orchestrator that operates on VPS infrastructure.

---

# 2. System architecture

## 2.1 High-level architecture

```text
                         INTERNET
                            |
                    Cloudflare / DNS
                            |
                    EDGE GATEWAY LAYER
                      HAProxy / Envoy
                            |
                  CLOUDFORGE CONTROL PLANE
                            |
          +-----------------+-----------------+
          |                 |                 |
       Dashboard         REST API         Webhooks
          |                 |                 |
          +-----------------+-----------------+
                            |
                   ORCHESTRATION ENGINE
                            |
          +-----------------+-----------------+
          |                 |                 |
      Deployment        Resource          Scheduling
       Engine           Manager             Engine
          |                 |                 |
          +-----------------+-----------------+
                            |
                   DURABLE JOB QUEUE
                            |
                   VPS AGENT PROTOCOL
                            |
              WireGuard / Private Network
                            |
       +--------------------+--------------------+
       |                    |                    |
    VPS NODE 1           VPS NODE 2           VPS NODE 3
       |                    |                    |
    Go Agent             Go Agent             Go Agent
       |                    |                    |
    Docker               Docker               Docker
       |                    |                    |
    Laravel              Laravel              Databases
    Workers              Next.js              PostgreSQL
    Redis                Workers              MySQL
       |                    |                    |
       +--------------------+--------------------+
                            |
                   OBJECT STORAGE
                    S3-compatible
```

## 2.2 Architectural layers

### A. Control plane

Responsibilities:

- Authentication.
- Organizations.
- Teams and permissions.
- Projects.
- Applications.
- Environments.
- Infrastructure inventory.
- Deployment orchestration.
- Database provisioning requests.
- Domain management.
- Scaling policies.
- Resource accounting.
- Billing readiness.
- Audit logs.

Technology:

- Laravel 13.
- PHP.
- Inertia.js v3.
- React.
- PostgreSQL.
- Redis.

### B. Agent layer

Responsibilities:

- VPS registration.
- Heartbeats.
- Docker management.
- Build execution coordination.
- Container lifecycle management.
- Volume management.
- Network management.
- Resource collection.
- Database provisioning.
- Application health checks.
- Deployment activation.
- Cleanup operations.

Technology:

- Go.
- Docker Engine API.
- BuildKit client.
- HTTPS/mTLS agent protocol.
- Linux systemd.

### C. Data plane

Responsibilities:

- Running applications.
- Running workers.
- Running databases.
- Running caches.
- Persistent storage.
- Application networking.
- Request routing.

Technology:

- Docker.
- Caddy.
- HAProxy or Envoy.
- PostgreSQL.
- MySQL.
- Redis/Valkey.

### D. Observability layer

Responsibilities:

- Infrastructure metrics.
- Application metrics.
- Deployment logs.
- Container logs.
- Error tracking.
- Alerts.
- Resource consumption.

Technology:

- Prometheus.
- Grafana.
- Loki.
- OpenTelemetry.
- Sentry integration.

---

# 3. Infrastructure architecture

## 3.1 VPS node types

CloudForge should support logical node roles.

| Node type   | Responsibilities                   |
| ----------- | ---------------------------------- |
| Control     | Control plane services             |
| Application | Laravel, PHP, Node.js and workers  |
| Database    | PostgreSQL, MySQL and Redis        |
| Build       | Isolated image builds              |
| Edge        | Reverse proxy and load balancing   |
| Storage     | Backup and object-storage services |
| General     | Multiple approved workloads        |

A physical VPS can have multiple roles, but production users should be able to separate them.

## 3.2 Node lifecycle

```text
UNREGISTERED
    |
    v
INSTALLATION_PENDING
    |
    v
AGENT_REGISTERED
    |
    v
HEALTH_CHECKING
    |
    v
READY
    |
    v
ACTIVE
    |
    +----> DRAINING
    |          |
    |          v
    |       MAINTENANCE
    |
    +----> UNREACHABLE
    |
    +----> ERROR
```

Each node should have:

- Unique UUID.
- Organization ownership.
- Hostname.
- Public IP.
- Private IP.
- Operating system.
- Architecture.
- CPU cores.
- Total RAM.
- Available disk capacity.
- Docker version.
- Agent version.
- Node roles.
- Health status.
- Last heartbeat.
- Reserved resources.
- Allocated resources.

## 3.3 VPS registration

Registration workflow:

1. User creates a node in the dashboard.
2. CloudForge creates a short-lived enrollment token.
3. User executes the installation command on the VPS.
4. Installer downloads the verified agent release.
5. Agent validates the enrollment token.
6. Agent establishes its authenticated connection.
7. CloudForge verifies node capabilities.
8. Agent reports Docker and system information.
9. Control plane marks the node ready.
10. Node becomes available for scheduling.

The installation token must be single-use, short-lived and bound to the intended organization and node.

Do not allow the installation script to expose long-lived control-plane credentials.

## 3.4 Networking

Use a private WireGuard network for node-to-node communication.

Example:

```text
Control Plane: 10.80.0.1
Edge Node 1:   10.80.0.10
App Node 1:    10.80.0.20
App Node 2:    10.80.0.21
DB Node 1:     10.80.0.30
Build Node 1:  10.80.0.40
```

These are illustrative addresses.

Requirements:

- Private network communication.
- Firewall restrictions.
- No public database ports.
- No public Docker API.
- Agent outbound connectivity.
- Encrypted node-to-node communication.
- Network health monitoring.

For the first release, use Docker networks per node and private WireGuard routing between nodes. Do not introduce a distributed container overlay without a concrete requirement.

## 3.5 Edge routing

Recommended design:

- HAProxy or Envoy for centralized traffic distribution.
- Caddy for TLS termination where appropriate.
- Cloudflare for DNS, proxying and WAF.
- Health-checked backend registration.
- Dynamic route configuration.

Support:

- Multiple application replicas.
- Multiple backend nodes.
- HTTPS.
- WebSockets.
- Custom domains.
- Wildcard domains.
- Traffic draining.
- Health-based routing.

For high availability, deploy at least two edge gateways behind a provider load balancer or a suitable failover mechanism.

---

# 4. Application runtime and framework detection

## 4.1 Supported frameworks

Initial support:

| Framework            | Runtime                                     |
| -------------------- | ------------------------------------------- |
| Laravel              | PHP-FPM                                     |
| Laravel Octane       | RoadRunner / FrankenPHP / supported runtime |
| Next.js              | Node.js                                     |
| Nuxt                 | Node.js                                     |
| Express              | Node.js                                     |
| NestJS               | Node.js                                     |
| Static websites      | Nginx / Caddy                               |
| Generic applications | Dockerfile                                  |
| Other frameworks     | Buildpacks where supported                  |

Laravel must receive first-class support.

## 4.2 Laravel detection

Inspect:

- composer.json.
- composer.lock.
- artisan.
- package.json.
- vite.config.js.
- phpunit.xml.
- Laravel framework dependencies.
- PHP version constraints.
- Required PHP extensions.
- Existing Dockerfile.

Detection result:

```json
{
  "framework": "laravel",
  "framework_version": "13",
  "php_version": "8.4",
  "build_system": "composer-vite",
  "has_vite": true,
  "has_horizon": false,
  "has_reverb": false,
  "has_octane": false,
  "recommended_runtime": "php-fpm"
}
```

This is an example detection response, not a fixed version guarantee.

Framework detection must be deterministic and version-aware.

## 4.3 Build system

Use Docker BuildKit.

Build lifecycle:

```text
SOURCE_CHECKOUT
    |
DEPENDENCY_INSTALL
    |
FRONTEND_BUILD
    |
APPLICATION_BUILD
    |
IMAGE_CREATION
    |
IMAGE_SCAN
    |
IMAGE_PUBLISH
    |
DEPLOYMENT_READY
```

Build requirements:

- Immutable source commit.
- Reproducible dependency installation.
- Build cache.
- Build logs.
- Configurable build arguments.
- Private registry support.
- Image digest tracking.
- Build cancellation.
- Build timeout.
- Artifact cleanup.
- Secret isolation.

Never expose build secrets through Docker build arguments or logs. Use BuildKit secret mounts.

Do not execute untrusted customer build scripts on the control-plane server. Build on dedicated isolated workers. For arbitrary customer code, use stronger isolation such as gVisor or Kata Containers.

---

# 5. Laravel deployment engine

## 5.1 Application creation

Required fields:

- Application name.
- Organization.
- Project.
- Environment.
- Git provider.
- Repository.
- Branch.
- Deployment node group.
- Framework.
- PHP version.
- Runtime type.

Optional configuration:

- Build command.
- Start command.
- Install command.
- Application port.
- Health-check path.
- Persistent storage.
- Environment variables.
- Resource limits.

## 5.2 Laravel deployment presets

Default production build:

```bash
composer install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader

npm ci
npm run build
```

Laravel release preparation:

```bash
php artisan optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

These commands should be configured according to the detected application. Not every Laravel application supports every cache command or frontend build step.

Generate APP_KEY only when the application does not already have a valid key.

## 5.3 Laravel process groups

Every Laravel application can define multiple processes.

| Process   | Example                   |
| --------- | ------------------------- |
| Web       | PHP-FPM + web server      |
| Octane    | Laravel Octane server     |
| Queue     | php artisan queue:work    |
| Horizon   | php artisan horizon       |
| Scheduler | php artisan schedule:work |
| Reverb    | php artisan reverb:start  |

Process groups must have independent scaling policies.

The scheduler must use a distributed lease or another single-active-scheduler mechanism to prevent duplicate scheduled execution.

## 5.4 Deployment strategies

Support:

1. Rolling deployments.
2. Blue-green deployments.
3. Immediate replacement.
4. Rollback.

Recommended default: blue-green deployment for small and medium applications where spare capacity exists.

Deployment process:

```text
CREATE RELEASE
    |
BUILD IMAGE
    |
RUN RELEASE TASKS
    |
START NEW CONTAINERS
    |
WAIT FOR HEALTH CHECKS
    |
REGISTER NEW BACKENDS
    |
SWITCH TRAFFIC
    |
DRAIN OLD CONTAINERS
    |
REMOVE OLD RELEASE AFTER RETENTION
```

A deployment must not become active until health checks succeed.

Migration failures must stop deployment activation.

Database migrations must execute once per release, with locking. They must not execute independently inside every replica.

Support expand-and-contract migrations for zero-downtime schema evolution.

## 5.5 Rollback

Store:

- Release ID.
- Image digest.
- Git commit.
- Build configuration.
- Runtime configuration version.
- Environment reference.
- Deployment status.
- Previous active release.

Rollback means reactivating a previous compatible image and configuration.

It does not automatically reverse database schema migrations.

---

# 6. Database platform

CloudForge supports two provisioning modes.

## 6.1 Shared cluster

Multiple applications share a database server or cluster, but receive separate databases and users.

Example:

```text
PostgreSQL Cluster
    |
    +-- Application A
    |      Database: app_a
    |      User: app_a_user
    |
    +-- Application B
    |      Database: app_b
    |      User: app_b_user
    |
    +-- Application C
           Database: app_c
           User: app_c_user
```

Benefits:

- Lower infrastructure cost.
- Easier capacity utilization.
- Faster provisioning.
- Centralized backups.
- Easier upgrades.

Limitations:

- Shared CPU and memory.
- Shared failure domain.
- Potential noisy-neighbor effects.

## 6.2 Dedicated instance

Each application receives a dedicated database container or server.

Example:

```text
Database Node
    |
    +-- PostgreSQL Instance A
    |
    +-- PostgreSQL Instance B
    |
    +-- MySQL Instance C
```

Benefits:

- Better resource isolation.
- Custom configuration.
- Independent upgrades.
- Independent backup policies.
- Easier workload separation.

## 6.3 Database provisioning workflow

```text
USER REQUEST
    |
VALIDATE QUOTA
    |
SELECT DATABASE NODE
    |
SELECT PROVISIONING MODE
    |
ALLOCATE STORAGE
    |
CREATE INSTANCE OR DATABASE
    |
CREATE DATABASE USER
    |
GENERATE CREDENTIALS
    |
CONFIGURE PRIVATE NETWORK
    |
VERIFY CONNECTION
    |
STORE ENCRYPTED SECRET
    |
ATTACH TO APPLICATION
    |
READY
```

## 6.4 Database resource configuration

PostgreSQL:

- Version.
- CPU limit.
- Memory limit.
- Storage limit.
- Database name.
- Database user.
- Backup schedule.
- Retention.
- Connection limit.
- High availability option.

MySQL:

- Version.
- CPU limit.
- Memory limit.
- Storage limit.
- Database name.
- Database user.
- Backup schedule.
- Retention.
- Connection limit.

Redis:

- Version.
- Memory limit.
- Persistence mode.
- Eviction policy.
- Authentication.
- Private network configuration.

Do not assume that Redis persistence is equivalent to durable queue storage. Important queues require appropriate durability and recovery policies.

## 6.5 Database attachment

Automatically inject:

```env
DB_CONNECTION=pgsql
DB_HOST=private-database-host
DB_PORT=5432
DB_DATABASE=application_database
DB_USERNAME=application_user
DB_PASSWORD=generated_secret
```

CloudForge must support application-level environment-variable overrides.

When a database credential rotates, CloudForge must update the secret, coordinate application restarts and verify connectivity.

## 6.6 Backups and recovery

Implement:

- Scheduled backups.
- On-demand backups.
- Encrypted backup storage.
- Retention policies.
- Backup verification.
- Restore to a new instance.
- Point-in-time recovery where supported.
- Backup audit history.

Never rely exclusively on backups stored on the same VPS as the database.

---

# 7. Multi-tenant database schema

Use PostgreSQL for CloudForge's control-plane database.

Use UUID primary keys for externally visible resource identifiers.

Use organization-scoped authorization throughout the application.

## 7.1 Identity and tenancy

### organizations

| Column        | Type           |
| ------------- | -------------- |
| id            | UUID           |
| name          | VARCHAR        |
| slug          | VARCHAR UNIQUE |
| owner_user_id | UUID           |
| plan          | VARCHAR        |
| status        | VARCHAR        |
| settings      | JSONB          |
| created_at    | TIMESTAMP      |
| updated_at    | TIMESTAMP      |

### users

| Column            | Type           |
| ----------------- | -------------- |
| id                | UUID           |
| name              | VARCHAR        |
| email             | VARCHAR UNIQUE |
| password          | VARCHAR        |
| email_verified_at | TIMESTAMP      |
| created_at        | TIMESTAMP      |
| updated_at        | TIMESTAMP      |

### organization_members

| Column          | Type      |
| --------------- | --------- |
| id              | UUID      |
| organization_id | UUID      |
| user_id         | UUID      |
| role            | VARCHAR   |
| status          | VARCHAR   |
| joined_at       | TIMESTAMP |

Unique constraint: organization_id + user_id.

### organization_invitations

- id
- organization_id
- email
- role
- token_hash
- expires_at
- accepted_at
- invited_by
- created_at

### organization_api_tokens

- id
- organization_id
- name
- token_hash
- abilities
- last_used_at
- expires_at
- created_at

Never store raw API tokens.

## 7.2 Projects and environments

### projects

- id
- organization_id
- name
- slug
- description
- created_at
- updated_at

### environments

- id
- organization_id
- project_id
- name
- slug
- type
- created_at
- updated_at

Examples:

- development
- staging
- production

## 7.3 Infrastructure

### servers

- id
- organization_id
- name
- hostname
- public_ip
- private_ip
- region
- operating_system
- architecture
- cpu_cores
- total_memory_bytes
- total_disk_bytes
- docker_version
- agent_version
- status
- last_heartbeat_at
- metadata
- created_at
- updated_at

### server_roles

- id
- server_id
- role
- created_at

### server_capabilities

- id
- server_id
- capability
- version
- metadata

### server_enrollments

- id
- organization_id
- server_id
- token_hash
- expires_at
- consumed_at
- created_at

### server_metrics

- id
- server_id
- recorded_at
- cpu_usage
- memory_usage
- disk_usage
- network_in
- network_out
- metadata

Use time-series retention and aggregation. Do not keep unlimited high-frequency metrics in the transactional control database.

## 7.4 Applications

### applications

- id
- organization_id
- project_id
- environment_id
- name
- slug
- repository_id
- framework
- framework_version
- runtime
- runtime_version
- deployment_strategy
- status
- configuration
- created_at
- updated_at

### application_processes

- id
- application_id
- name
- process_type
- command
- desired_replicas
- minimum_replicas
- maximum_replicas
- cpu_limit
- memory_limit
- health_check
- configuration
- created_at
- updated_at

### application_instances

- id
- application_id
- process_id
- server_id
- release_id
- container_id
- status
- health_status
- started_at
- last_health_check_at

### application_domains

- id
- application_id
- hostname
- certificate_status
- dns_status
- verified_at
- created_at

### application_environment_variables

- id
- application_id
- key
- encrypted_value
- is_secret
- environment
- created_at
- updated_at

Prefer a dedicated secrets manager or encrypted secret references for sensitive values rather than exposing secrets through ordinary application records.

## 7.5 Git repositories

### git_providers

- id
- organization_id
- provider
- installation_id
- encrypted_access_token
- metadata
- status

### repositories

- id
- organization_id
- git_provider_id
- external_repository_id
- name
- full_name
- clone_url
- default_branch
- private
- metadata

### repository_webhooks

- id
- repository_id
- webhook_id
- secret_reference
- status

## 7.6 Deployments

### builds

- id
- organization_id
- application_id
- source_commit
- branch
- build_status
- build_node_id
- image_reference
- image_digest
- build_logs_reference
- started_at
- completed_at

### releases

- id
- application_id
- build_id
- release_number
- image_digest
- configuration_version
- status
- created_at

### deployments

- id
- organization_id
- application_id
- release_id
- deployment_strategy
- status
- started_at
- completed_at
- initiated_by
- failure_reason

### deployment_steps

- id
- deployment_id
- step
- status
- sequence
- started_at
- completed_at
- output_reference
- error_message

## 7.7 Databases

### database_clusters

- id
- organization_id
- name
- engine
- engine_version
- mode
- primary_server_id
- status
- configuration
- created_at

### database_instances

- id
- organization_id
- cluster_id
- server_id
- name
- engine
- version
- storage_bytes
- cpu_limit
- memory_limit
- status
- configuration

### databases

- id
- organization_id
- database_instance_id
- application_id
- database_name
- database_user
- encrypted_credentials_reference
- status
- created_at

### database_attachments

- id
- database_id
- application_id
- connection_name
- environment
- secret_reference
- status

### database_backups

- id
- database_instance_id
- backup_type
- storage_location
- encryption_key_reference
- size_bytes
- status
- started_at
- completed_at
- expires_at

### database_restores

- id
- backup_id
- target_instance_id
- status
- started_at
- completed_at
- initiated_by

## 7.8 Infrastructure operations

### provisioning_operations

- id
- organization_id
- resource_type
- resource_id
- operation
- idempotency_key
- status
- progress
- requested_by
- started_at
- completed_at
- error_details

### agent_commands

- id
- server_id
- command_id
- operation
- payload_reference
- status
- issued_at
- acknowledged_at
- completed_at

### scaling_policies

- id
- application_id
- process_id
- metric
- target_value
- minimum_replicas
- maximum_replicas
- cooldown_seconds
- enabled

### audit_logs

- id
- organization_id
- actor_id
- action
- resource_type
- resource_id
- ip_address
- metadata
- created_at

### resource_allocations

- id
- organization_id
- server_id
- resource_type
- resource_id
- cpu_reserved
- memory_reserved
- storage_reserved
- status

## 7.9 Important database constraints

- All organization-owned resources must be organization-scoped.
- Enforce foreign keys.
- Add unique constraints to resource slugs within their proper scope.
- Add indexes to organization_id and frequently queried status fields.
- Enforce deployment idempotency.
- Store timestamps in UTC.
- Encrypt secrets at rest.
- Never delete database resources without explicit confirmation and retention checks.
- Use soft deletion where recovery is required.
- Prevent cross-organization resource attachment.

Use Laravel policies for application authorization and consider PostgreSQL Row-Level Security as an additional defense, not a substitute for correct application authorization.

---

# 8. API specification

API base path:

```text
/api/v1
```

All control-plane endpoints must use authenticated authorization and organization-scoped access.

## 8.1 Authentication

```http
POST /api/v1/auth/login
POST /api/v1/auth/logout
POST /api/v1/auth/refresh
GET  /api/v1/me
```

Use secure sessions for the dashboard and scoped API tokens for programmatic access.

## 8.2 Organizations

```http
GET    /api/v1/organizations
POST   /api/v1/organizations
GET    /api/v1/organizations/{organization}
PATCH  /api/v1/organizations/{organization}
GET    /api/v1/organizations/{organization}/members
POST   /api/v1/organizations/{organization}/invitations
DELETE /api/v1/organizations/{organization}/members/{member}
```

## 8.3 Servers

```http
GET    /api/v1/servers
POST   /api/v1/servers
GET    /api/v1/servers/{server}
POST   /api/v1/servers/{server}/enrollment-token
POST   /api/v1/servers/{server}/install
POST   /api/v1/servers/{server}/drain
POST   /api/v1/servers/{server}/maintenance
POST   /api/v1/servers/{server}/restart-agent
DELETE /api/v1/servers/{server}
GET    /api/v1/servers/{server}/metrics
GET    /api/v1/servers/{server}/resources
```

Server deletion must verify that there are no active resources that would be orphaned.

## 8.4 Git integrations

```http
GET  /api/v1/integrations/github/installations
POST /api/v1/integrations/github/installations
GET  /api/v1/repositories
GET  /api/v1/repositories/{repository}
POST /api/v1/repositories/{repository}/detect
```

Webhook:

```http
POST /api/v1/webhooks/github/{installation}
```

Verify webhook signatures before processing events.

## 8.5 Projects and applications

```http
GET    /api/v1/projects
POST   /api/v1/projects
GET    /api/v1/projects/{project}
PATCH  /api/v1/projects/{project}
DELETE /api/v1/projects/{project}

GET    /api/v1/applications
POST   /api/v1/applications
GET    /api/v1/applications/{application}
PATCH  /api/v1/applications/{application}
DELETE /api/v1/applications/{application}

POST   /api/v1/applications/{application}/deploy
POST   /api/v1/applications/{application}/restart
POST   /api/v1/applications/{application}/stop
POST   /api/v1/applications/{application}/scale
POST   /api/v1/applications/{application}/rollback
GET    /api/v1/applications/{application}/logs
GET    /api/v1/applications/{application}/metrics
```

## 8.6 Databases

```http
GET    /api/v1/databases
POST   /api/v1/databases
GET    /api/v1/databases/{database}
PATCH  /api/v1/databases/{database}
DELETE /api/v1/databases/{database}

POST   /api/v1/databases/{database}/attach
POST   /api/v1/databases/{database}/detach
POST   /api/v1/databases/{database}/backups
GET    /api/v1/databases/{database}/backups
POST   /api/v1/databases/{database}/restore
POST   /api/v1/databases/{database}/rotate-credentials
```

## 8.7 Domains

```http
GET    /api/v1/applications/{application}/domains
POST   /api/v1/applications/{application}/domains
POST   /api/v1/domains/{domain}/verify
POST   /api/v1/domains/{domain}/issue-certificate
DELETE /api/v1/domains/{domain}
```

## 8.8 Deployment APIs

```http
GET  /api/v1/applications/{application}/deployments
GET  /api/v1/deployments/{deployment}
GET  /api/v1/deployments/{deployment}/steps
GET  /api/v1/deployments/{deployment}/logs
POST /api/v1/deployments/{deployment}/cancel
POST /api/v1/deployments/{deployment}/rollback
```

## 8.9 API response conventions

Successful asynchronous operation:

```json
{
  "data": {
    "operation_id": "uuid",
    "resource_id": "uuid",
    "status": "pending"
  }
}
```

Validation error:

```json
{
  "message": "Validation failed.",
  "errors": {
    "name": ["The name field is required."]
  }
}
```

Every mutation should support an idempotency key.

Return HTTP 202 for accepted asynchronous provisioning and deployment operations.

Use consistent pagination, filtering, sorting and structured error responses.

---

# 9. Agent protocol

This is a critical subsystem. It must be versioned, authenticated and designed for unreliable network conditions.

## 9.1 Communication model

Recommended initial implementation:

- Outbound HTTPS connections from agents.
- Mutual TLS.
- Agent heartbeat.
- Long polling or streaming for commands.
- Signed operation payloads.
- Acknowledgement and execution reporting.
- Retry with exponential backoff.

Avoid requiring inbound SSH connections from the control plane for routine operations.

## 9.2 Agent enrollment

```text
Control Plane
    |
Create enrollment token
    |
User installs agent
    |
Agent sends enrollment request
    |
Validate token
    |
Issue client certificate
    |
Register agent
    |
Establish authenticated session
```

The agent certificate must be revocable and rotatable.

## 9.3 Agent command envelope

Example:

```json
{
  "protocol_version": "1.0",
  "command_id": "uuid",
  "server_id": "uuid",
  "operation": "deployment.activate",
  "issued_at": "2026-11-10T12:00:00Z",
  "expires_at": "2026-11-10T12:05:00Z",
  "idempotency_key": "deployment-release-123",
  "payload": {
    "application_id": "uuid",
    "release_id": "uuid",
    "image_digest": "sha256:...",
    "desired_replicas": 2
  }
}
```

The payload must be validated against an operation-specific schema.

## 9.4 Supported agent operations

```text
server.inspect
server.health
server.update

docker.image.pull
docker.container.create
docker.container.start
docker.container.stop
docker.container.remove

build.start
build.cancel
build.status

deployment.prepare
deployment.activate
deployment.rollback
deployment.health_check

database.create
database.update
database.backup
database.restore
database.delete

network.create
network.attach
network.detach

volume.create
volume.mount
volume.backup

metrics.collect
logs.collect
```

These operations are not arbitrary shell execution commands.

The agent must implement a strict operation allowlist.

## 9.5 Command execution lifecycle

```text
RECEIVED
   |
VALIDATED
   |
ACKNOWLEDGED
   |
EXECUTING
   |
COMPLETED / FAILED
```

Agent requirements:

- Persist execution state locally.
- Deduplicate commands.
- Reject expired commands.
- Report structured errors.
- Support cancellation where safe.
- Resume reconciliation after restart.
- Limit concurrent operations.
- Apply operation timeouts.
- Maintain a local audit trail.
- Verify image digests.
- Never log secrets.

## 9.6 Agent reconciliation

Every agent periodically reports its actual state.

CloudForge compares:

```text
Desired State
     |
     v
Control Plane Resource Model
     |
     v
Actual Agent State
     |
     v
Reconciliation Engine
     |
     v
Safe Corrective Operation
```

Examples:

- A required application container is missing.
- A database container is unhealthy.
- An old release remains after a failed deployment.
- A node has insufficient capacity.
- A deployment is still marked active although its container stopped.

Do not blindly recreate stateful resources. Database recovery and volume operations need explicit safety rules.

---

# 10. Deployment workflow

## 10.1 GitHub integration

Use a GitHub App rather than asking users for broad personal access tokens.

Required permissions should be limited to:

- Read repository metadata.
- Read source contents.
- Receive repository webhooks.
- Manage checks or deployment statuses where necessary.

Use installation-scoped access tokens.

## 10.2 Deployment sequence

```text
GitHub Push
    |
Webhook Verification
    |
Find Application
    |
Validate Branch and Environment
    |
Create Deployment Record
    |
Schedule Build
    |
Clone Exact Commit
    |
Framework Detection
    |
Build Image
    |
Scan Image
    |
Publish Image
    |
Select Runtime Nodes
    |
Prepare New Release
    |
Run Migration Once
    |
Start Containers
    |
Health Checks
    |
Activate Routing
    |
Drain Old Containers
    |
Mark Deployment Successful
```

## 10.3 Build isolation

The build system must:

- Run in isolated workers.
- Use short-lived workspaces.
- Restrict network access where practical.
- Prevent host Docker socket exposure to untrusted build processes.
- Protect build secrets.
- Enforce CPU and memory limits.
- Enforce disk limits.
- Enforce timeouts.
- Delete temporary build workspaces.

## 10.4 Preview deployments

Future capability:

```text
Pull Request #45
    |
Create Temporary Environment
    |
Build Application
    |
Provision Temporary Resources
    |
Generate Preview Domain
    |
Run Health Checks
    |
Publish Preview URL
    |
Destroy Resources After PR Closure
```

Preview databases should be isolated and must not contain production data unless explicitly approved and securely sanitized.

---

# 11. Security model

Security is a mandatory foundation, not a later enhancement.

## 11.1 Tenant isolation

- Every resource belongs to an organization.
- Authorization must validate organization membership.
- Application policies must verify ownership.
- Database attachments must remain organization-scoped.
- Resource queries must never trust client-provided organization IDs.
- Team roles must be enforced server-side.
- Audit every privileged operation.

## 11.2 Roles

Initial roles:

| Role      | Permissions                               |
| --------- | ----------------------------------------- |
| Owner     | Full organization control                 |
| Admin     | Infrastructure and application management |
| Developer | Application deployment and configuration  |
| Operator  | Monitoring and operational actions        |
| Viewer    | Read-only access                          |

Custom roles can be introduced later.

## 11.3 Secret management

Use envelope encryption:

- Master encryption key stored outside the database.
- Per-organization data encryption keys.
- Encrypted secret payloads.
- Key versioning.
- Key rotation.
- Restricted decryption access.
- Audit records for secret access.

Never return decrypted secrets through ordinary list endpoints.

Show a generated database password only when necessary and only through a controlled reveal operation.

## 11.4 Agent security

- Mutual TLS.
- Short-lived enrollment tokens.
- Certificate revocation.
- Command expiry.
- Payload validation.
- Signed agent releases.
- Agent update verification.
- Restricted local permissions.
- No public Docker API.
- No arbitrary shell commands.
- Rate-limited operations.

Important: access to the Docker daemon is effectively root-level access on its host. A compromised agent or unrestricted Docker API can compromise the entire node. The agent therefore needs a narrow privileged execution boundary and a hardened installation model.

## 11.5 Container security

Default container configuration:

- Non-root user.
- Read-only root filesystem where practical.
- Dropped Linux capabilities.
- No privileged mode.
- CPU limits.
- Memory limits.
- PID limits.
- Seccomp profile.
- Restricted host mounts.
- Explicit networking rules.
- Image scanning.
- Container health checks.

For public multi-tenant hosting of arbitrary customer code, add gVisor or Kata isolation for higher-risk workloads.

## 11.6 Database security

- No public database ports by default.
- Dedicated database credentials.
- Least-privilege grants.
- Encrypted backups.
- Private networking.
- TLS where applicable.
- Credential rotation.
- Restore authorization.
- Deletion safeguards.

## 11.7 Audit and incident response

Log:

- Authentication events.
- Membership changes.
- API token creation.
- Server registration.
- Server removal.
- Deployment operations.
- Database creation and deletion.
- Secret rotation.
- Domain modifications.
- Scaling changes.
- Backup and restore operations.

---

# 12. Scheduling and scaling engine

## 12.1 Resource scheduling

The scheduler should select nodes based on:

- Available CPU.
- Available memory.
- Available disk.
- Node role.
- Organization placement constraints.
- Resource reservations.
- Existing workload pressure.
- Node health.
- Network availability.

Initial scheduling algorithm:

1. Filter nodes by role and health.
2. Filter nodes by architecture and runtime capability.
3. Check reserved capacity.
4. Calculate placement score.
5. Select the highest-scoring eligible node.
6. Reserve capacity atomically.
7. Schedule the operation.
8. Reconcile actual allocation.

Do not use simple CPU utilization alone to determine placement.

## 12.2 Autoscaling

Application autoscaling:

- Minimum replicas.
- Maximum replicas.
- CPU target.
- Memory target.
- Request concurrency target.
- Cooldown period.
- Scale-up policy.
- Scale-down policy.

Worker autoscaling:

- Queue backlog.
- Queue wait time.
- Job execution duration.
- Worker concurrency.
- Available worker capacity.

## 12.3 Scaling boundaries

CloudForge must distinguish:

1. Container scaling on one VPS.
2. Application scaling across multiple VPS nodes.
3. Infrastructure scaling by registering or provisioning additional VPS servers.

Only the first two are available with existing infrastructure.

Automatic provider provisioning is a future adapter system.

## 12.4 Database scaling

Future capabilities:

- Read replicas.
- Connection pooling.
- Automated failover.
- Database placement.
- Capacity alerts.
- Managed database upgrades.

Do not automatically shard databases or migrate primary databases between VPS nodes in MVP.

---

# 13. Observability and monitoring

## 13.1 Platform monitoring

Monitor:

- Control-plane health.
- API latency.
- Queue backlog.
- Failed jobs.
- Agent heartbeats.
- Server CPU.
- Server memory.
- Disk capacity.
- Network traffic.
- Container status.
- Database availability.
- Database connections.
- Build duration.
- Deployment success rate.
- HTTP response latency.

## 13.2 Logs

Three main log categories:

1. Infrastructure logs.
2. Deployment/build logs.
3. Application/container logs.

Every log entry should include relevant identifiers:

- organization_id
- project_id
- application_id
- deployment_id
- server_id
- correlation_id

Never collect secrets into logs.

## 13.3 Alerts

Initial alerts:

- Server unavailable.
- Disk usage critical.
- Database unavailable.
- Application unhealthy.
- Deployment failed.
- Backup failed.
- Certificate renewal failed.
- Queue backlog excessive.
- Agent version outdated.

---

# 14. User interface specification

The dashboard should be a polished modern developer platform, not an administrative CRUD interface.

## 14.1 Main navigation

```text
CloudForge

Overview

Workspace
  Projects
  Applications
  Databases
  Storage

Infrastructure
  Servers
  Node Groups
  Networking
  Builds

Operations
  Deployments
  Logs
  Monitoring
  Backups

Organization
  Members
  API Tokens
  Usage
  Settings
```

## 14.2 Main screens

### Overview

- Applications count.
- Servers count.
- Deployment status.
- Resource utilization.
- Recent activity.
- Infrastructure alerts.

### Application dashboard

Tabs:

- Overview.
- Deployments.
- Runtime.
- Processes.
- Domains.
- Environment variables.
- Databases.
- Logs.
- Metrics.
- Settings.

### Database dashboard

- Database status.
- Engine and version.
- Connection information.
- Attached applications.
- Storage usage.
- Backups.
- Restore operations.
- Credentials.
- Metrics.

### Server dashboard

- Server health.
- CPU and memory.
- Disk.
- Running containers.
- Installed agent version.
- Node roles.
- Allocated resources.
- Maintenance actions.

## 14.3 UX requirements

- Responsive design.
- Dark and light themes.
- Clear provisioning states.
- Real-time deployment progress.
- Live logs.
- Search and filtering.
- Confirmation for destructive operations.
- Useful empty states.
- Clear error explanations.
- No fake success states.
- Accessible components.
- Keyboard navigation.

---

# 15. Deployment and operational reliability

## 15.1 Control-plane deployment

The control plane must itself be deployable through CloudForge eventually, but bootstrap should initially use a separate, documented installation process.

Initial control-plane services:

- Laravel web/API.
- Queue workers.
- Scheduler.
- PostgreSQL.
- Redis.
- WebSocket service if required.

## 15.2 Control-plane availability

Future production topology:

- Two application instances.
- Highly available control database.
- Durable queue processing.
- Redis with appropriate persistence or managed HA.
- External backup storage.
- Redundant edge routing.

The control plane must not be the only place where the actual application state exists. Agents need local reconciliation capability.

## 15.3 Disaster recovery

Define:

- Recovery Point Objective (RPO).
- Recovery Time Objective (RTO).
- Control-plane backup policy.
- Database backup policy.
- Agent recovery process.
- Encryption-key recovery.
- Server replacement procedure.

Test recovery regularly rather than assuming backups are valid.

---

# 16. Repository structure

```text
cloudforge/
│
├── apps/
│   ├── control-plane/
│   │   ├── app/
│   │   │   ├── Domains/
│   │   │   │   ├── Identity/
│   │   │   │   ├── Organizations/
│   │   │   │   ├── Projects/
│   │   │   │   ├── Applications/
│   │   │   │   ├── Deployments/
│   │   │   │   ├── Builds/
│   │   │   │   ├── Infrastructure/
│   │   │   │   ├── Servers/
│   │   │   │   ├── Databases/
│   │   │   │   ├── Networking/
│   │   │   │   ├── Scaling/
│   │   │   │   ├── Monitoring/
│   │   │   │   ├── Security/
│   │   │   │   └── Billing/
│   │   ├── database/
│   │   ├── routes/
│   │   └── resources/
│   │
│   └── agent/
│       ├── cmd/
│       │   └── cloudforge-agent/
│       ├── internal/
│       │   ├── enrollment/
│       │   ├── protocol/
│       │   ├── docker/
│       │   ├── buildkit/
│       │   ├── deployment/
│       │   ├── database/
│       │   ├── networking/
│       │   ├── volumes/
│       │   ├── metrics/
│       │   ├── security/
│       │   └── reconciliation/
│       └── packaging/
│
├── packages/
│   ├── agent-protocol/
│   ├── deployment-specifications/
│   ├── framework-detectors/
│   ├── runtime-images/
│   └── shared-contracts/
│
├── infrastructure/
│   ├── development/
│   ├── control-plane/
│   ├── database/
│   ├── networking/
│   ├── monitoring/
│   └── build-workers/
│
├── docs/
│   ├── architecture/
│   ├── api/
│   ├── agent/
│   ├── security/
│   ├── deployment/
│   └── operations/
│
└── README.md
```

---

# 17. Phased implementation roadmap

## Phase 1 — Foundation

Deliver:

- Laravel control plane.
- Authentication.
- Organizations.
- Teams and roles.
- Projects and environments.
- VPS registration.
- Go agent.
- Enrollment and certificates.
- Heartbeats.
- Server inventory.
- Docker inspection.

Acceptance criteria:

- Register at least three VPS nodes.
- View their health in the dashboard.
- Detect agent disconnection.
- Revoke an agent certificate.
- Enforce organization isolation.

## Phase 2 — Deployment engine

Deliver:

- GitHub App.
- Repository connection.
- Webhook verification.
- Framework detection.
- BuildKit integration.
- PHP runtime.
- Laravel deployment.
- Deployment logs.
- Immutable releases.
- Health checks.
- Domains and HTTPS.
- Rollback.

Acceptance criteria:

- Deploy a Laravel application from GitHub.
- Automatically detect the PHP runtime.
- Build frontend assets.
- Publish an immutable image.
- Run health checks.
- Activate a release.
- Roll back successfully.

## Phase 3 — Database platform

Deliver:

- PostgreSQL shared clusters.
- MySQL shared clusters.
- Dedicated database instances.
- Redis resources.
- Credential generation.
- Private networking.
- Database attachment.
- Backup and restore.

Acceptance criteria:

- Provision a PostgreSQL database with one click.
- Attach it to Laravel automatically.
- Verify a real database connection.
- Create a dedicated database instance.
- Restore a verified backup.

## Phase 4 — Laravel Cloud experience

Deliver:

- Automatic Laravel configuration.
- Environment management.
- Horizon.
- Queue workers.
- Scheduler.
- Octane.
- Reverb.
- Process management.
- Preview deployments.
- Zero-downtime deployments.

Acceptance criteria:

- Deploy a real Laravel application without writing a Dockerfile.
- Configure the database and Redis from the dashboard.
- Deploy frontend assets.
- Start workers.
- Execute scheduled tasks exactly once per schedule.
- Perform a safe release rollback.

## Phase 5 — Multi-server scaling

Deliver:

- Node groups.
- Resource scheduler.
- Capacity reservations.
- Application replicas.
- Cross-node routing.
- Worker autoscaling.
- Queue-based scaling.
- Node draining.
- Capacity alerts.

Acceptance criteria:

- Deploy one application across multiple VPS nodes.
- Route traffic to healthy instances.
- Scale replicas up and down.
- Drain a server without interrupting healthy workloads.
- Prevent over-allocation of resources.

## Phase 6 — Production hardening

Deliver:

- Security review.
- Isolation testing.
- Backup verification.
- Disaster recovery.
- Agent upgrades.
- Control-plane HA.
- Rate limiting.
- Usage accounting.
- Operational documentation.

Acceptance criteria:

- Recover from agent disconnection.
- Recover from a failed deployment.
- Restore a database backup.
- Recover control-plane data.
- Pass organization-isolation tests.
- Complete penetration testing.

---

# 18. Implementation instructions for AI coding agents

The implementation must be incremental and repository-aware.

General rules:

1. Inspect the repository before changing files.
2. Do not assume existing code or infrastructure.
3. Write architecture decision records.
4. Create migrations before using new database fields.
5. Use service classes for domain operations.
6. Use typed DTOs and explicit contracts.
7. Keep controllers thin.
8. Use queues for long-running infrastructure operations.
9. Use idempotent jobs.
10. Add tests for every infrastructure lifecycle.
11. Never expose raw Docker APIs.
12. Never execute arbitrary user-provided shell commands through the control plane.
13. Never store plaintext credentials.
14. Use real implementations rather than mocked production behavior.
15. Document configuration and deployment requirements.

## Phase 1 implementation prompt

```text
OBJECTIVE

Implement CloudForge Phase 1: the complete control-plane
foundation and Go infrastructure agent.

CONTEXT

CloudForge is a self-hosted multi-tenant PaaS.
The control plane uses Laravel 13, Inertia v3 and React.
The infrastructure agent uses Go.
VPS nodes run Linux and Docker Engine.

REQUIREMENTS

1. Inspect the repository and document the current state.
2. Implement authentication and organization management.
3. Implement organization memberships and RBAC.
4. Implement projects and environments.
5. Implement server registration.
6. Implement short-lived single-use enrollment tokens.
7. Implement secure agent enrollment.
8. Implement agent certificate provisioning and revocation.
9. Implement mTLS agent communication.
10. Implement agent heartbeats.
11. Implement server inventory collection.
12. Implement Docker capability inspection.
13. Implement node health states.
14. Implement agent command persistence and acknowledgements.
15. Implement the server management dashboard.

SECURITY

- Enforce tenant isolation.
- Never expose Docker sockets.
- Use mTLS.
- Validate every agent operation.
- Store token hashes.
- Encrypt sensitive values.
- Implement audit logging.
- Do not implement arbitrary shell execution.

TESTING

- Organization isolation.
- Enrollment token expiration.
- Enrollment token replay prevention.
- Agent certificate revocation.
- Heartbeat timeout.
- Agent reconnect.
- Command deduplication.
- Unauthorized server access.

DELIVERABLES

- Working Laravel control plane.
- Working Go agent.
- Database migrations.
- Agent installation package.
- API documentation.
- Protocol documentation.
- Automated tests.
- Development environment.
- Deployment documentation.

Do not start Phase 2 until Phase 1 acceptance tests pass.
```

## Phase 2 implementation prompt

```text
OBJECTIVE

Implement CloudForge's production application deployment engine.

REQUIREMENTS

1. Integrate GitHub App authentication.
2. Implement repository discovery.
3. Implement signed webhook validation.
4. Implement branch and commit tracking.
5. Implement framework detection.
6. Implement PHP and Laravel detection.
7. Implement BuildKit integration.
8. Implement isolated build workers.
9. Build immutable Docker images.
10. Implement image digest tracking.
11. Implement deployment records and release records.
12. Implement application runtime provisioning.
13. Implement application process management.
14. Implement health checks.
15. Implement dynamic routing registration.
16. Implement custom domains and TLS.
17. Implement deployment logs.
18. Implement cancellation.
19. Implement rollback.

LARAVEL SUPPORT

- PHP version detection.
- Composer installation.
- Vite asset compilation.
- APP_KEY generation.
- Laravel optimization.
- Runtime configuration.
- PHP-FPM.
- Optional Octane.
- Persistent storage configuration.

SECURITY

- Isolate build execution.
- Protect build secrets.
- Verify image digests.
- Prevent arbitrary host execution.
- Apply resource limits.
- Enforce organization ownership.

TESTING

- Successful Laravel deployment.
- Build failure.
- Invalid repository.
- Webhook replay.
- Health-check failure.
- Deployment cancellation.
- Rollback.
- Agent disconnection during deployment.

DELIVERABLES

Provide a fully working GitHub-to-production deployment pipeline.
```

## Phase 3 implementation prompt

```text
OBJECTIVE

Implement CloudForge's database provisioning and resource
attachment platform.

REQUIREMENTS

1. Implement PostgreSQL provisioning.
2. Implement MySQL provisioning.
3. Implement Redis provisioning.
4. Implement shared database clusters.
5. Implement dedicated database instances.
6. Implement secure database users.
7. Implement random credential generation.
8. Implement private networking.
9. Implement database attachment.
10. Implement automatic Laravel DB environment configuration.
11. Implement connection verification.
12. Implement credential rotation.
13. Implement persistent storage.
14. Implement automated backups.
15. Implement backup restoration.
16. Implement resource lifecycle management.

SECURITY

- No public database ports by default.
- Dedicated database users.
- Least-privilege permissions.
- Encrypted credential storage.
- Private networking.
- Secure backup storage.
- Destructive-operation safeguards.

TESTING

- PostgreSQL provisioning.
- MySQL provisioning.
- Redis provisioning.
- Database attachment.
- Laravel connectivity.
- Credential rotation.
- Backup integrity.
- Restore recovery.
- Tenant isolation.

DELIVERABLE

A functional one-click database platform.
```

## Phase 4 implementation prompt

```text
OBJECTIVE

Implement the Laravel Cloud-like developer experience.

REQUIREMENTS

1. Automatic Laravel deployment configuration.
2. Environment-variable management.
3. Database and Redis resource attachment.
4. Horizon worker provisioning.
5. Scheduler provisioning with distributed locking.
6. Octane runtime support.
7. Reverb support.
8. Multiple process groups.
9. Preview environments.
10. Release management.
11. Zero-downtime deployment.
12. Deployment history.
13. One-click rollback.
14. Real-time deployment logs.
15. Runtime monitoring.

TESTING

Deploy an actual Laravel application with:
- PostgreSQL.
- Redis.
- Vite.
- Horizon.
- Scheduled tasks.
- Custom domain.
- SSL.

Verify that the entire process works from the dashboard without
requiring manual server configuration.
```

## Phase 5 implementation prompt

```text
OBJECTIVE

Implement multi-server scheduling and application autoscaling.

REQUIREMENTS

1. Implement node groups.
2. Implement resource reservations.
3. Implement capacity-aware placement.
4. Implement cross-node application deployment.
5. Implement dynamic edge routing.
6. Implement health-based traffic distribution.
7. Implement node draining.
8. Implement application replica scaling.
9. Implement worker autoscaling.
10. Implement queue-backlog metrics.
11. Implement CPU and memory metrics.
12. Implement scaling policies.
13. Implement cooldown periods.
14. Implement capacity alerts.
15. Implement failure recovery.

TESTING

- Multi-node placement.
- Insufficient capacity.
- Node failure.
- Replica scaling.
- Worker scaling.
- Traffic routing.
- Node draining.
- Recovery after agent reconnection.

DELIVERABLE

A multi-VPS application platform with functioning
capacity-aware scheduling and autoscaling.
```

## Phase 6 implementation prompt

```text
OBJECTIVE

Prepare CloudForge for production operation.

REQUIREMENTS

1. Security audit.
2. Tenant isolation review.
3. Agent privilege review.
4. Container isolation review.
5. Backup and recovery testing.
6. Control-plane high availability.
7. Agent update mechanism.
8. Deployment failure recovery.
9. Observability.
10. Alerting.
11. Resource usage accounting.
12. Rate limiting.
13. API documentation.
14. Installation documentation.
15. Disaster recovery documentation.
16. Load testing.
17. Penetration testing.

Do not claim production readiness until critical security,
recovery and reliability tests pass.
```

---

# 19. Final definition of done

CloudForge 1.0 is complete when a developer can:

1. Create an organization.
2. Register three or more VPS servers.
3. Connect GitHub.
4. Create a Laravel application.
5. Deploy it without writing a Dockerfile.
6. Provision PostgreSQL with one click.
7. Attach PostgreSQL to the application.
8. Provision Redis.
9. Configure Laravel workers.
10. Add a domain and obtain HTTPS.
11. Deploy automatically after Git pushes.
12. View live deployment logs.
13. Roll back to an earlier release.
14. Back up and restore the database.
15. Run application replicas on multiple VPS nodes.
16. Monitor infrastructure health.
17. Manage team permissions.
18. Recover from node and deployment failures.

**The most important acceptance test:**

A developer with a GitHub Laravel repository and three registered VPS servers must be able to create a production application, attach PostgreSQL and Redis, configure HTTPS and deploy successfully through the CloudForge dashboard without manually SSHing into servers to configure the application.

That is the product experience we are building.

---

# 20. Future roadmap beyond 1.0

After the core platform becomes reliable:

- Automatic VPS provisioning through Hetzner, OVH and DigitalOcean adapters.
- Kubernetes deployment backend as an optional execution mode.
- Multi-region infrastructure.
- Database high availability.
- Automated database failover.
- Managed object storage.
- Private container registries.
- AI-assisted deployment diagnostics.
- AI resource recommendations.
- Infrastructure cost optimization.
- Usage-based billing.
- Customer-facing hosting plans.
- Marketplace for runtime templates and integrations.

The platform should be extensible enough to add these without rewriting the control plane or agent protocol.
