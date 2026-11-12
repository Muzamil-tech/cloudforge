## Two things to install

CloudForge has a **control plane** (Laravel app) and an **agent** (Go binary on each VPS).

### 1. Control plane (local development)

Prerequisites: PHP 8.4, Composer, Node 20+, SQLite (default) or PostgreSQL, Redis optional.

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

### Important caveat

The installer pulls release archives from GitHub Releases. Those archives are **not published yet**, so the `curl | sh` path won't work until you publish them:

```sh
cd apps\agent
make package VERSION=1.0.0
```

That produces `dist/cloudforge-agent_linux_amd64.tar.gz`, `dist/cloudforge-agent_linux_arm64.tar.gz`, and `dist/SHA256SUMS`, which need uploading to a GitHub release under `Muzamil-tech/cloudforge`. Until then, install the agent from source:

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

I'm currently finishing the deployment docs and installation guide in `docs/`, which will include the exact production steps.
