# CloudForge

CloudForge is a self-hosted platform-as-a-service control plane for VPS
infrastructure. It combines a Laravel 13 + Inertia/React control plane with a Go
agent that runs on each managed Linux host.

## Repository layout

| Path | Purpose |
| --- | --- |
| `apps/control-plane` | Laravel 13 control plane, JSON API, dashboard and tests |
| `apps/agent` | Go agent, installer packaging and tests |
| `infrastructure` | systemd unit and bootstrap wrapper |
| `docs` | Architecture, API, agent, deployment and implementation records |

## Current status

Milestone 0 and Milestone 1 (self-hosted bootstrap) are implemented and tested
locally. The control plane, organization/RBAC foundation, agent identity,
enrollment, certificate rotation, heartbeats, inventory, metrics, durable
commands and audit log are in place.

The first database-resource slice is now in place: managed PostgreSQL and Redis
containers are provisioned through the agent, with encrypted credentials,
start/stop/delete and live state. MySQL and application deployment remain later
milestones.

Managed resources pull `postgres:16-alpine` and `redis:7-alpine` from Docker Hub.
The VPS must be able to reach the Docker registry, or a registry mirror must be
configured on the Docker daemon.

Existing installations must rerun the installer to update the control plane and
agent to the resource-capable build (`v1.2.0` or later).

**Not yet verified in real infrastructure:** clean VPS installation, real
PostgreSQL/Redis, real Docker, DNS/TLS, and the three-VPS Phase 1 acceptance run.
See [`docs/implementation/PROGRESS.md`](docs/implementation/PROGRESS.md) for the
requirement-by-requirement verification ledger. Phase 2 has not started.

## One-command installation

On a clean Ubuntu 22.04/24.04 or Debian 12 VPS, point a DNS `A` record at the
server and run:

```sh
curl -fsSL https://raw.githubusercontent.com/Muzamil-tech/cloudforge/main/install.sh \
  | sudo CLOUDFORGE_DOMAIN=cloud.example.com \
         CLOUDFORGE_ACME_EMAIL=you@example.com \
         bash
```

The installer provisions PHP 8.4, PostgreSQL, Redis, Nginx, Node.js, Docker,
Composer, the Laravel control plane, a Let's Encrypt certificate, the scheduler,
the queue worker and the local Go agent. It builds the agent from source, so a
published release is not required.

Open `https://cloud.example.com/register` to create the owner account. The first
account can always sign up; after that, registration is closed unless
`CLOUDFORGE_OPEN_REGISTRATION=1` is set. The local bootstrap server is claimed
when the first organization is created.

For a disposable development host without a domain:

```sh
curl -fsSL https://raw.githubusercontent.com/Muzamil-tech/cloudforge/main/install.sh \
  | sudo CLOUDFORGE_HTTP_ONLY=1 bash
```

If you deploy from a private fork, pass a read-only token for the source clone:

```sh
curl -fsSL https://raw.githubusercontent.com/Muzamil-tech/cloudforge/main/install.sh \
  | sudo CLOUDFORGE_REPO_TOKEN=github_pat_... \
         CLOUDFORGE_DOMAIN=cloud.example.com \
         CLOUDFORGE_ACME_EMAIL=you@example.com \
         bash
```

The Phase 1 dashboard provides accounts, organizations, projects, environments,
servers, members, API tokens and audit history. It also provisions managed
PostgreSQL and Redis resources through the agent. Application deployment, MySQL
and multi-server placement are later milestones.

## Local development

Requirements: PHP 8.4, Composer, Node.js, SQLite for local development, and npm.

```sh
cd apps/control-plane
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
php artisan serve
```

Open `http://127.0.0.1:8000`, register the first account, and create an
organization. Registration is open while no user exists.

## Agent

```sh
cd apps/agent
go test ./...
go vet ./...
go build -o cloudforge-agent ./cmd/cloudforge-agent
```

Release archives are published as GitHub releases:

```sh
VERSION=1.0.0 sh packaging/package.sh
```

Release `v1.2.0` contains `cloudforge-agent_linux_amd64.tar.gz`,
`cloudforge-agent_linux_arm64.tar.gz` and `SHA256SUMS` under
`Muzamil-tech/cloudforge`. The dashboard-generated installer resolves the latest
release automatically.

## Verification

```sh
cd apps/control-plane
php artisan test --compact
vendor/bin/pint --format agent
npm run build
sh tests/shell/installer_test.sh
bash tests/shell/one_command_installer_test.sh ../../install.sh

cd ../..
bash -n install.sh

cd apps/agent
go test ./...
go vet ./...
```

## Documentation

- [Architecture](docs/architecture/overview.md)
- [UI repository audit](docs/ui-ux/REPOSITORY_AUDIT.md)
- [Design system](docs/ui-ux/DESIGN_SYSTEM.md)
- [API reference](docs/api/README.md)
- [Agent protocol](docs/agent/protocol.md)
- [Agent installation](docs/agent/installation.md)
- [Control-plane deployment](docs/deployment/control-plane.md)
- [Implementation progress](docs/implementation/PROGRESS.md)
- [Repository audit](docs/implementation/REPOSITORY_AUDIT.md)

## Security

- Enrollment tokens are single-use, hashed at rest and short-lived.
- Bootstrap credentials are mode `0600`, scoped to one host, and deleted after
  enrollment.
- Agents authenticate with a client certificate and a per-request RSA signature.
- There is no arbitrary shell execution and no Docker mutation path in Phase 1.
- Never commit `.env` files, the agent CA private key, or API tokens.
