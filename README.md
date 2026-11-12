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

**Not yet verified in real infrastructure:** clean VPS installation, real
PostgreSQL/Redis, real Docker, DNS/TLS, and the three-VPS Phase 1 acceptance run.
See [`docs/implementation/PROGRESS.md`](docs/implementation/PROGRESS.md) for the
requirement-by-requirement verification ledger. Phase 2 has not started.

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

Release archives consumed by the installer:

```sh
VERSION=1.0.0 sh packaging/package.sh
```

The archives are not published yet, so the default `curl | sh` installer path
requires a GitHub release containing
`cloudforge-agent_linux_amd64.tar.gz`, `cloudforge-agent_linux_arm64.tar.gz` and
`SHA256SUMS`.

## Verification

```sh
cd apps/control-plane
php artisan test --compact
vendor/bin/pint --format agent
npm run build
sh tests/shell/installer_test.sh

cd ../agent
go test ./...
go vet ./...
```

## Documentation

- [Architecture](docs/architecture/overview.md)
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
