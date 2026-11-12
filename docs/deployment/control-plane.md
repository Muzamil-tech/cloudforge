# Control-plane deployment

This guide installs the CloudForge control plane on a single Linux host and
registers that host as the first managed server. It describes the code in
`apps/control-plane` as it exists in this repository. Commands that are specific
to the bootstrap flow are covered in more detail in
[agent installation](../agent/installation.md).

## Topology

A production installation needs:

- One control-plane host running PHP 8.4, a web server, PostgreSQL and Redis.
- A TLS certificate for the public hostname.
- One or more VPS nodes running Docker and the CloudForge agent.
- Outbound HTTPS from each node to the control plane.

The control plane and the first agent can share a host. That is the self-hosted
bootstrap case: the same machine serves the dashboard and runs the
`cloudforge-agent` service.

## One-command installation

For a clean Ubuntu 22.04/24.04 or Debian 12 VPS, the root `install.sh` performs
the whole deployment. Point a DNS `A` record at the server first, then run:

```sh
curl -fsSL https://raw.githubusercontent.com/Muzamil-tech/cloudforge/main/install.sh \
  | sudo CLOUDFORGE_DOMAIN=cloud.example.com \
         CLOUDFORGE_ACME_EMAIL=you@example.com \
         bash
```

The installer:

1. Installs PHP 8.4, PostgreSQL, Redis, Nginx, Node.js, Docker, Composer and
   Certbot.
2. Creates the PostgreSQL role and database with a generated password.
3. Clones or updates the repository under `/var/www/cloudforge`.
4. Writes a production `.env` without printing or logging secrets.
5. Installs PHP and JavaScript dependencies and builds the dashboard.
6. Configures PHP-FPM, Nginx and a Let's Encrypt certificate.
7. Writes the scheduler cron entry and the queue-worker systemd unit.
8. Builds the Go agent from source and enrolls the local bootstrap server.
9. Verifies `/up` and the first-run `/register` page.

Open `https://cloud.example.com/register` to create the first account. The first
account is always allowed; after it exists, registration closes unless
`CLOUDFORGE_OPEN_REGISTRATION=1` is set. Creating the first organization claims
the local bootstrap server.

### Installer variables

| Variable | Default | Purpose |
| --- | --- | --- |
| `CLOUDFORGE_DOMAIN` | — | Public hostname. Required unless `CLOUDFORGE_HTTP_ONLY=1`. |
| `CLOUDFORGE_ACME_EMAIL` | — | Let's Encrypt account email. Required for HTTPS. |
| `CLOUDFORGE_HTTP_ONLY` | `0` | Set to `1` for a development install without TLS. The agent enrolls through a loopback listener. |
| `CLOUDFORGE_OPEN_REGISTRATION` | `0` | Set to `1` to keep public signup open after the first account. |
| `CLOUDFORGE_SKIP_AGENT` | `0` | Set to `1` to install the dashboard without the local agent. |
| `CLOUDFORGE_INSTALL_DOCKER` | `1` | Install Docker Engine and enable the daemon. |
| `CLOUDFORGE_APP_DIR` | `/var/www/cloudforge` | Source and deployment directory. |
| `CLOUDFORGE_REPO_URL` | `https://github.com/Muzamil-tech/cloudforge.git` | Source repository. |
| `CLOUDFORGE_REF` | `main` | Branch or tag to deploy. |
| `CLOUDFORGE_REPO_TOKEN` | — | Read token for a private source repository. |
| `CLOUDFORGE_PUBLIC_IP` | detected | Override public-address detection. |
| `CLOUDFORGE_DB_NAME` / `CLOUDFORGE_DB_USER` | `cloudforge` | Database names. |
| `CLOUDFORGE_PHP_VERSION` | `8.4` | PHP package version. |
| `CLOUDFORGE_NODE_MAJOR` | `22` | NodeSource major version. |
| `CLOUDFORGE_GO_VERSION` | `1.24.6` | Go toolchain used to build the agent. |
| `CLOUDFORGE_AGENT_BINARY` | — | Use an existing executable instead of building the agent. |

The installer is idempotent. Re-running it updates the checkout, dependencies,
migrations, Nginx configuration and agent while preserving `.env` secrets and the
database role.

Managed PostgreSQL and Redis resources use `postgres:16-alpine` and
`redis:7-alpine`. The agent reuses a local image first, then tries
`mirror.gcr.io` before Docker Hub and falls back to `public.ecr.aws`. Each
registry attempt has a three-minute timeout so a blocked CDN cannot hold the
command queue until the create command expires. Custom images can be pinned with
`CLOUDFORGE_POSTGRES_IMAGE` and `CLOUDFORGE_REDIS_IMAGE`.

A `resource.create` is idempotent. If an agent restarts after creating the
container but before the control plane receives the result, the agent inspects
the existing labeled container and recovers its password from the Docker
configuration. The project and database pages expose **Recover credentials** (or
**Retry provisioning** for a failed resource) so the volume does not have to be
deleted. Starting a resource without credentials is rejected; recover it first.

### Using a private fork

The public repository needs no credentials. If you deploy from a private fork,
`curl | sudo bash` cannot clone it without a credential. Pass a read-only token
through `CLOUDFORGE_REPO_TOKEN`. The token is used only for the clone and is not
written to `.env` or the remote URL.

### HTTP-only development mode

```sh
curl -fsSL https://raw.githubusercontent.com/Muzamil-tech/cloudforge/main/install.sh \
  | sudo CLOUDFORGE_HTTP_ONLY=1 bash
```

This serves the dashboard over the public IP over HTTP and configures Nginx on
`127.0.0.1:8080` so the local agent can enroll over a loopback URL. It is not a
production-safe public endpoint. Additional VPS agents require HTTPS.

### Email delivery

The default `.env` uses `MAIL_MAILER=log`. Set SMTP values in `.env` and re-run
`php artisan config:cache` to send invitations by email. Until then, invitation
links and messages are only written to the Laravel log.

## Requirements

| Component | Minimum | Notes |
| --- | --- | --- |
| Operating system | Ubuntu 24.04 LTS or equivalent | Commands below assume Debian packages. |
| PHP | 8.4 | Extensions: `ctype`, `curl`, `dom`, `fileinfo`, `mbstring`, `openssl`, `pdo_pgsql`, `redis`, `tokenizer`, `xml`. |
| Composer | 2.x | Used to install application dependencies. |
| PostgreSQL | 16 | Production database. SQLite is for local development and tests only. |
| Redis | 7 | Sessions and cache. A queue worker is optional in Phase 1 because no queued jobs ship yet. |
| Node.js | 22 | Only needed if you build frontend assets on the host. |
| Web server | Nginx 1.24+ | Must proxy PHP-FPM and keep the agent long-poll alive. |
| Certbot or equivalent | — | TLS certificate issuance and renewal. |

The agent certificate authority uses PHP's OpenSSL extension. It generates an
RSA-4096 CA on first use; the operation can take a few seconds.

## Install packages

```sh
sudo apt update
sudo apt install -y \
  nginx postgresql redis-server \
  php8.4-fpm php8.4-cli php8.4-pgsql php8.4-redis php8.4-curl \
  php8.4-mbstring php8.4-xml php8.4-zip php8.4-intl \
  composer git unzip curl
```

Install Node.js 22 from your distribution or NodeSource if you build assets on
the host:

```sh
node --version
npm --version
```

## Create the database

```sh
sudo -u postgres psql <<'SQL'
CREATE ROLE cloudforge LOGIN PASSWORD 'replace-with-a-long-random-password';
CREATE DATABASE cloudforge OWNER cloudforge;
SQL
```

Do not reuse the database password anywhere else. Store it in the control-plane
`.env`, not in shell history.

## Deploy the application

```sh
sudo mkdir -p /var/www/cloudforge
sudo chown "$USER":www-data /var/www/cloudforge
```

Place the repository at `/var/www/cloudforge`. The Laravel application is the
`apps/control-plane` directory:

```sh
cd /var/www/cloudforge/apps/control-plane
composer install --no-dev --optimize-autoloader --no-interaction
cp .env.example .env
php artisan key:generate --force
```

Edit `.env`. At minimum set the application URL, database credentials, Redis
connection and mail sender. The full reference is below.

```sh
php artisan migrate --force
```

`migrate` creates the schema, including `server_enrollments`, the agent command
queue and the nullable bootstrap columns on `servers`. Re-running
`cloudforge:bootstrap` after a migration never duplicates a server record
because the lookup is keyed by `/etc/machine-id`.

Build the frontend:

```sh
npm ci
npm run build
```

Set ownership and permissions:

```sh
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
sudo chmod 640 .env
sudo chown root:www-data .env
```

The agent CA is created under `storage/app/private/agent-ca` on first
enrollment. The PHP user must be able to create and read that directory. Do not
share it between control-plane installations.

## Environment reference

The values below are read by `config/cloudforge.php`. Defaults are shown where
the application supplies one.

### Application

| Variable | Default | Purpose |
| --- | --- | --- |
| `APP_NAME` | `CloudForge` | Product name in the dashboard. |
| `APP_ENV` | `production` | Must be `production` on a public host. |
| `APP_KEY` | — | Required. Encrypts sessions and application secrets. Back it up. |
| `APP_DEBUG` | `false` | Must stay `false`; debug pages leak configuration. |
| `APP_URL` | — | Public HTTPS URL. Used for the generated install command and links. |
| `APP_TIMEZONE` | `UTC` | Keep UTC for consistent audit timestamps. |
| `LOG_CHANNEL` | `stack` | Laravel log channel. |
| `LOG_LEVEL` | `info` | Use `debug` only while diagnosing. |

### Database, session and cache

| Variable | Default | Purpose |
| --- | --- | --- |
| `DB_CONNECTION` | `pgsql` | PostgreSQL in production. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | — | Database connection. |
| `SESSION_DRIVER` | `redis` | Dashboard sessions. |
| `SESSION_ENCRYPT` | `true` | Encrypt session payloads. |
| `SESSION_SECURE_COOKIE` | `true` | Cookies are HTTPS-only. |
| `SESSION_LIFETIME` | `120` | Idle session lifetime in minutes. |
| `CACHE_STORE` | `redis` | Application cache. |
| `QUEUE_CONNECTION` | `redis` | Queue connection. No queued jobs ship in Phase 1. |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | `127.0.0.1`, `6379`, none | Redis connection. |

Redis session and cache data is disposable. Losing Redis logs users out and
clears cached values; it does not lose server, project or audit records.

### Registration and agent settings

| Variable | Default | Purpose |
| --- | --- | --- |
| `CLOUDFORGE_ALLOW_REGISTRATION` | `true` | When false, registration succeeds only while no user exists or with a valid invitation. |
| `CLOUDFORGE_AGENT_ENROLLMENT_TTL_MINUTES` | `15` | Dashboard/API enrollment token lifetime. |
| `CLOUDFORGE_AGENT_HEARTBEAT_TIMEOUT_SECONDS` | `90` | Age after which a server becomes `unreachable`. |
| `CLOUDFORGE_AGENT_CERTIFICATE_TTL_DAYS` | `365` | Agent client certificate lifetime. |
| `CLOUDFORGE_AGENT_CA_PATH` | `storage/app/private/agent-ca` | CA directory. Must be persistent and backed up. |
| `CLOUDFORGE_AGENT_CA_COMMON_NAME` | `CloudForge Agent CA` | CA subject common name. Changing it does not reissue an existing CA. |
| `CLOUDFORGE_AGENT_COMMAND_TTL_SECONDS` | `300` | Default command expiry; `agent.restart` uses 60 seconds. |
| `CLOUDFORGE_AGENT_MAX_POLL_WAIT_SECONDS` | `25` | Maximum command long-poll duration. |
| `CLOUDFORGE_AGENT_CLOCK_SKEW_SECONDS` | `300` | Accepted signature timestamp skew. |
| `CLOUDFORGE_AGENT_INSTALL_URL` | `APP_URL/install.sh` | URL embedded in generated install commands. |
| `CLOUDFORGE_REQUIRE_CLIENT_CERT_HEADER` | `false` | Enable only when a trusted terminator forwards the verified client certificate. |
| `CLOUDFORGE_CLIENT_CERT_SERIAL_HEADER` | `X-Client-Cert-Serial` | Header carrying the forwarded serial or fingerprint. |
| `CLOUDFORGE_DOCKER_SOCKET` | `/var/run/docker.sock` | Docker socket used by the control-plane host inspector. Must be a Unix socket. |

### Self-hosted bootstrap

| Variable | Default | Purpose |
| --- | --- | --- |
| `CLOUDFORGE_CONTROL_PLANE_URL` | `APP_URL` | URL written into the bootstrap credential. |
| `CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH` | `/etc/cloudforge/bootstrap.env` | Short-lived shell credential. Mode `0600`. |
| `CLOUDFORGE_BOOTSTRAP_STATUS_PATH` | `/etc/cloudforge/bootstrap.json` | Status document without the token. Mode `0600`. |
| `CLOUDFORGE_BOOTSTRAP_TOKEN_TTL_MINUTES` | `30` | Bootstrap token lifetime. |
| `CLOUDFORGE_BOOTSTRAP_SERVER_NAME` | derived from hostname | Name of the bootstrap server record. |
| `CLOUDFORGE_MACHINE_ID` | — | Override for hosts without `/etc/machine-id`. Must be 32 lowercase hex characters and must match the agent. |
| `CLOUDFORGE_MACHINE_ID_PATH` | `/etc/machine-id` | Primary machine-id source. |
| `CLOUDFORGE_DBUS_MACHINE_ID_PATH` | `/var/lib/dbus/machine-id` | Fallback machine-id source. |

### Metrics

| Variable | Default | Purpose |
| --- | --- | --- |
| `CLOUDFORGE_METRICS_RETENTION_DAYS` | `14` | Metric retention before `metrics:prune` deletes old rows. |

After changing `.env`, rebuild the configuration cache. Runtime overrides passed
by `cloudforge:bootstrap` (`--url`, `--credential-path`, `--status-path`) take
precedence over the cached configuration for that command only.

## Web server and PHP-FPM

The agent command endpoint long-polls for up to 25 seconds. PHP-FPM and the web
server must allow that request to finish, and the worker pool must have room for
one blocked worker per concurrently polling agent.

PHP-FPM pool settings (`/etc/php/8.4/fpm/pool.d/www.conf`):

```ini
pm = dynamic
pm.max_children = 25
pm.start_servers = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 10
pm.max_requests = 500

request_terminate_timeout = 65s
```

`/etc/php/8.4/fpm/php.ini`:

```ini
max_execution_time = 60
```

Nginx site:

```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name cloudforge.example.com;

    root /var/www/cloudforge/apps/control-plane/public;
    index index.php;
    client_max_body_size 2m;

    ssl_certificate     /etc/letsencrypt/live/cloudforge.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/cloudforge.example.com/privkey.pem;

    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header Referrer-Policy strict-origin-when-cross-origin;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_read_timeout 65s;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}

server {
    listen 80;
    server_name cloudforge.example.com;
    return 301 https://$host$request_uri;
}
```

Keep the certificate chain complete. Agents verify the control-plane certificate
against the system trust store plus the optional `CLOUDFORGE_CA_FILE`.

If a load balancer terminates TLS in front of Nginx, configure Laravel trusted
proxies in `bootstrap/app.php` for that proxy address. Without it, rate limiting
sees the proxy address and every agent shares one enrollment limit. The
application does not ship a proxy allowlist.

Reload after changes:

```sh
sudo nginx -t
sudo systemctl reload nginx
sudo systemctl reload php8.4-fpm
```

## Queue worker and scheduler

No queued jobs ship in Phase 1, so a worker is not required for the current
feature set. If you add queued work, run a supervised worker:

```ini
# /etc/systemd/system/cloudforge-queue.service
[Unit]
Description=CloudForge queue worker
After=network.target redis-server.service postgresql.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/cloudforge/apps/control-plane
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

The scheduler is required: it marks stale servers unreachable and prunes
metrics. Add a cron entry for the PHP user:

```cron
* * * * * cd /var/www/cloudforge/apps/control-plane && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Scheduled jobs:

| Command | Schedule | Effect |
| --- | --- | --- |
| `servers:mark-unreachable` | Every minute | Marks servers whose last heartbeat is older than 90 seconds. |
| `metrics:prune` | Daily at 03:15 | Deletes metric samples older than the retention window. |
| `queue:prune-failed` | Daily at 03:45 | Deletes failed jobs older than seven days. |

## Register the control-plane host

Run the bootstrap wrapper as root from the repository:

```sh
cd /var/www/cloudforge
sudo sh infrastructure/scripts/bootstrap-node.sh
```

The wrapper provisions the local server, writes `/etc/cloudforge/bootstrap.env`
and `/etc/cloudforge/bootstrap.json` with mode `0600`, installs the agent with
`CLOUDFORGE_BOOTSTRAP=1`, and removes the credential after the certificate is
issued.

If `www-data` cannot read the repository, set `CLOUDFORGE_APP_DIR` and
`CLOUDFORGE_PHP_USER` for the wrapper. The wrapper runs artisan as the PHP user
so the application cache and CA files keep the correct ownership.

The equivalent manual sequence is:

```sh
php artisan cloudforge:bootstrap
sudo env CLOUDFORGE_BOOTSTRAP=1 sh public/install.sh
```

`cloudforge:bootstrap` never prints the token. The JSON output includes the
server, machine-id and credential paths, but not the token or its hash.

## First login and organization

1. Open `https://cloudforge.example.com`.
2. Register the first user. With no users in the database, registration is open
   even when `CLOUDFORGE_ALLOW_REGISTRATION=false`.
3. Create the organization on the onboarding screen.
4. Onboarding shows up to five unclaimed bootstrap nodes. Creating the
   organization claims every unclaimed bootstrap server for it.
5. Confirm the server appears in **Servers** with the `bootstrap` badge and an
   `agent_registered` or `ready` status.

If an organization already existed before the agent enrolled, the bootstrap
server is claimed automatically only when exactly one organization exists. With
several organizations, claim it explicitly:

```sh
php artisan cloudforge:bootstrap:claim --organization=<slug-or-uuid>
```

To attribute the claim to a specific owner or administrator:

```sh
php artisan cloudforge:bootstrap:claim \
  --organization=<slug-or-uuid> \
  --user=owner@example.com
```

## Close registration

Once the first owner exists, disable open registration unless invitations are
part of your onboarding:

```dotenv
CLOUDFORGE_ALLOW_REGISTRATION=false
```

```sh
php artisan config:cache
```

Closed registration still accepts a valid invitation. The registration page
returns `403` when registration is closed and no invitation is present.

## Verify the installation

```sh
curl -fsS https://cloudforge.example.com/up
php artisan cloudforge:bootstrap:status --json
systemctl status cloudforge-agent --no-pager
journalctl -u cloudforge-agent -n 50 --no-pager
```

Expected state:

- `/up` returns HTTP `200`.
- `cloudforge:bootstrap:status` shows `claimed_by` once the organization exists.
- The agent logs an issued certificate fingerprint and its expiry.
- The dashboard server detail shows a heartbeat within 30 seconds.
- Queueing `server.health` from the server page completes with a result.
- **Audit log** contains `server.bootstrap_provisioned`, `server.enrolled` and
  `server.bootstrap_claimed`.

Check the scheduler:

```sh
php artisan schedule:list
```

## Backup and restore

Back up three things together:

1. PostgreSQL: `pg_dump -Fc -U cloudforge cloudforge > cloudforge-$(date +%F).dump`
2. The agent CA: `tar -C storage/app/private -czf agent-ca-$(date +%F).tar.gz agent-ca`
3. `.env`, or at least `APP_KEY`.

The CA private key is a root credential. Anyone holding it can issue a
certificate that the control plane will trust. Store backups encrypted and
restrict access.

Restore order:

```sh
php artisan down --retry=60
pg_restore -U cloudforge -d cloudforge --clean --if-exists cloudforge.dump
tar -C storage/app/private -xzf agent-ca.tar.gz
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
php artisan up
```

Restoring the database without the CA leaves every enrolled agent with a
certificate signed by an unknown authority. Restoring the CA without the
database loses the certificate records and server identities. Keep them
together.

Redis is not part of the backup. After a Redis loss, users must log in again and
cached values are rebuilt.

## Upgrades

```sh
cd /var/www/cloudforge/apps/control-plane
php artisan down --retry=60

# Deploy the new code, then:
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
npm ci && npm run build

php artisan optimize:clear
php artisan optimize

sudo systemctl reload php8.4-fpm
sudo systemctl restart cloudforge-queue   # only if a worker is installed
php artisan up
```

The agent tolerates a short maintenance window: failed requests are retried, and
a server becomes `unreachable` only after 90 seconds without a heartbeat. A
longer window produces `server.unreachable` audit entries; the next heartbeat
restores the prior operational status.

Run the upgrade during a low-traffic period. Migrations are forward-only in this
repository; take a database backup before deploying.

## Security checklist

- `APP_DEBUG=false`, `APP_ENV=production`.
- HTTPS only; HTTP redirects to HTTPS.
- `CLOUDFORGE_ALLOW_REGISTRATION=false` after the first owner exists.
- Database and Redis listen on loopback or a private network, never the public
  internet.
- The Docker socket is a Unix socket and is not exposed over TCP.
- `storage/app/private/agent-ca` is readable only by the PHP user.
- `/etc/cloudforge/bootstrap.env` is deleted after enrollment and never
  committed to source control.
- API tokens are stored hashed and can be revoked; prefer short expiries.
- Backups of the database, CA and `APP_KEY` are encrypted.
- The host firewall exposes only `22`, `80` and `443` unless you have a specific
  reason to expose more.

## Troubleshooting

| Symptom | Cause and action |
| --- | --- |
| `SQLSTATE[08006]` on boot | PostgreSQL is unreachable or the credentials are wrong. Check `DB_*` and `pg_isready`. |
| Login redirects back to the login page | Session cookie is not being set. Confirm `APP_URL`, `SESSION_SECURE_COOKIE`, and that HTTPS terminates correctly. |
| Agent enrollment times out | Nginx or PHP-FPM is cutting the request short. Check `fastcgi_read_timeout`, `request_terminate_timeout` and the PHP error log. |
| Agent commands stay `pending` | The agent is not polling, or its certificate is invalid. Check `journalctl -u cloudforge-agent`, then the server's certificate history. |
| Commands complete but the page does not update | The dashboard polls; refresh the page. Check the browser network tab for `401` or `429`. |
| `unreachable` appears every minute | The agent cannot reach the control plane or the scheduler marks it before the first heartbeat. Check DNS, TLS and clock skew. |
| `The bootstrap credential ... must have mode 0600` | Fix the file mode or re-run `cloudforge:bootstrap`, which writes it atomically. |
| `This bootstrap enrollment token is not valid for this host` | The machine-id changed or the credential was copied to another host. Provision a new bootstrap token on the intended host. |
| `openssl` errors during enrollment | PHP's OpenSSL extension is missing or the CA directory is not writable by the PHP user. |
| 502 after enabling a proxy | PHP-FPM socket path in Nginx does not match the installed PHP version. |

For agent-side failures, see
[agent installation troubleshooting](../agent/installation.md#troubleshooting)
and the [agent protocol](../agent/protocol.md).
