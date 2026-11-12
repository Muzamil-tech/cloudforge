## Two things to install

CloudForge has a **control plane** (Laravel app) and an **agent** (Go binary on each VPS).

### 1. Control plane (local development)

Prerequisites: PHP 8.4, Composer, Node 20+, SQLite (default) or PostgreSQL, Redis optional.

curl -fsSL https://raw.githubusercontent.com/Muzamil-tech/cloudforge/main/install.sh \
 | CLOUDFORGE_DOMAIN=cloud.manzomaa.com \
 CLOUDFORGE_ACME_EMAIL=muzamil@manzomaa.com \
 bash

```powershell
cd apps\control-plane
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
php artisan serve
```

Then open `http://127.0.0.1:8000`:

1. Register the first account — registration is open while no users exist.
2. Create an organization.
3. Add a server under **Servers → New server**.
4. Click **Issue enrollment token** and copy the generated install command.

### 2. Agent (on a Linux VPS)

Once the control plane is publicly reachable over HTTPS, run the generated command on the VPS:

```sh
curl -fsSL https://your-control-plane/install.sh | sudo \
  env CLOUDFORGE_ENROLLMENT_TOKEN=<token> \
      CLOUDFORGE_CONTROL_PLANE_URL=https://your-control-plane \
      CLOUDFORGE_SERVER_ID=<server-uuid> sh
```

It downloads the release, verifies `SHA256SUMS`, creates the `cloudforge-agent` user, enrolls, installs the systemd unit, and starts the service. Requires Linux, systemd, root, and Docker for Docker inventory.

Release archives are published on GitHub Releases. The v1.0.0 release contains
`cloudforge-agent_linux_amd64.tar.gz`,
`cloudforge-agent_linux_arm64.tar.gz` and `SHA256SUMS` under
`Muzamil-tech/cloudforge`. The installer resolves the latest release
automatically. To rebuild locally:

```sh
cd apps/agent
VERSION=1.0.0 sh packaging/package.sh
```

The generated archives are in `apps/agent/dist/`. Source installation remains
available as an alternative:

```sh
cd apps/agent
go build -o cloudforge-agent ./cmd/cloudforge-agent

sudo ./cloudforge-agent enroll \
  --control-plane http://<control-plane>:8000 \
  --server-id <server-uuid> \
  --data-dir /var/lib/cloudforge-agent \
  --token <enrollment-token>

sudo ./cloudforge-agent run \
  --control-plane http://<control-plane>:8000 \
  --server-id <server-uuid> \
  --data-dir /var/lib/cloudforge-agent
```

Production steps are in `docs/deployment/control-plane.md`; agent details are in
`docs/agent/installation.md`.
