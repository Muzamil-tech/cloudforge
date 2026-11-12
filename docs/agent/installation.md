# Agent installation

This guide covers installing, enrolling, upgrading and removing the CloudForge
agent on a Linux VPS. It documents the behaviour that is implemented in
`apps/control-plane/public/install.sh`, `infrastructure/systemd/cloudforge-agent.service`
and `apps/agent`.

There are two supported installation paths:

| Path | Use when |
| --- | --- |
| Generated install command | Add another VPS to an existing organization. The dashboard or API shows a command containing a single-use enrollment token. |
| Self-hosted bootstrap | Install CloudForge itself. `cloudforge:bootstrap` registers the control-plane host and writes a short-lived, node-scoped credential that the installer consumes. |

Both paths use the same installer and the same agent enrollment protocol. The
only difference is where the enrollment token comes from and how it is
invalidated.

## Requirements

- Linux on `amd64` (x86_64) or `arm64` (aarch64).
- Root access for the installer.
- `systemd` as PID 1.
- `curl`, `tar`, `systemctl`, `runuser`, `useradd` and `getent`.
- `sha256sum` or `shasum` to verify the release archive.
- Docker is optional. The daemon socket must be a local Unix socket
  (`/var/run/docker.sock` by default). TCP, SSH and named-pipe endpoints are
  rejected by the agent.
- Outbound HTTPS access to the control plane. Plain HTTP is accepted only for
  loopback development addresses.

## Release assets

The installer downloads three files from `CLOUDFORGE_DOWNLOAD_BASE_URL`
(default `https://github.com/Muzamil-tech/cloudforge/releases/latest/download`):

```text
cloudforge-agent_linux_amd64.tar.gz
cloudforge-agent_linux_arm64.tar.gz
SHA256SUMS
```

The architecture is detected from `uname -m`. The archive must contain a
`cloudforge-agent` binary at its root. Its SHA-256 checksum is compared with the
entry for that archive in `SHA256SUMS`; a missing entry or a mismatch aborts the
installation before anything is written.

Build the archives from `apps/agent` with:

```sh
make package VERSION=1.0.0
```

## Installer environment

The installer reads these variables. Required variables are validated before
the download begins.

| Variable | Required | Default | Purpose |
| --- | --- | --- | --- |
| `CLOUDFORGE_ENROLLMENT_TOKEN` | Yes, unless an identity already exists | — | Single-use token. Never written to disk by the installer. |
| `CLOUDFORGE_CONTROL_PLANE_URL` | Yes | — | Public control-plane URL. Must be HTTPS except on loopback. |
| `CLOUDFORGE_SERVER_ID` | Yes | — | UUID of the server record that owns the token. |
| `CLOUDFORGE_DOWNLOAD_BASE_URL` | No | GitHub latest release | Release download base URL. |
| `CLOUDFORGE_INSTALL_DIR` | No | `/usr/local/bin` | Binary directory. |
| `CLOUDFORGE_CONFIG_DIR` | No | `/etc/cloudforge` | Configuration directory. |
| `CLOUDFORGE_DATA_DIR` | No | `/var/lib/cloudforge-agent` | Agent state and identity directory. |
| `CLOUDFORGE_DOCKER_SOCKET` | No | `/var/run/docker.sock` | Docker Unix socket. |
| `CLOUDFORGE_CA_FILE` | No | empty | Extra PEM CA bundle for the control-plane TLS certificate. |
| `CLOUDFORGE_AGENT_USER` | No | `cloudforge-agent` | System user that runs the agent. |
| `CLOUDFORGE_FORCE_ENROLL` | No | `0` | Set to `1` to replace an existing identity. Revoke the old certificate first. |
| `CLOUDFORGE_LOG_LEVEL` | No | `info` | `debug`, `info`, `warn` or `error`. |
| `CLOUDFORGE_MACHINE_ID` | No | from `/etc/machine-id` | 32-character hex override used for bootstrap inspection and enrollment. Export it for both the bootstrap wrapper and the installer. |
| `CLOUDFORGE_BOOTSTRAP` | No | `0` | Set to `1` to load a bootstrap credential file. |
| `CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH` | No | `/etc/cloudforge/bootstrap.env` | Bootstrap credential file. |

## Generated install command

When an owner or administrator creates a server and requests an enrollment
token, the control plane returns a command in this shape:

```sh
curl -fsSL https://cloudforge.example.com/install.sh | sudo \
  env CLOUDFORGE_ENROLLMENT_TOKEN='…' \
      CLOUDFORGE_CONTROL_PLANE_URL='https://cloudforge.example.com' \
      CLOUDFORGE_SERVER_ID='…' sh
```

The token expires after 15 minutes by default, can be used once, and is stored
only as a SHA-256 hash by the control plane. If the command is lost or the token
expires, issue a new one; do not reuse an old command.

## Self-hosted bootstrap

The self-hosted flow exists so a clean installation registers its own host
without an operator copying a server UUID and token from the dashboard.

### 1. Deploy the control plane

Complete the control-plane installation first, including migrations and a
working HTTPS endpoint. The agent cannot enroll before the control plane can
serve `/api/agent/v1/enroll`.

### 2. Provision the bootstrap server

Run the wrapper as root on the control-plane host:

```sh
sudo sh infrastructure/scripts/bootstrap-node.sh
```

The wrapper:

1. Checks that it is root and that `php`, `runuser`, `install` and `getent`
   exist.
2. Creates `/etc/cloudforge` with mode `0770`, owned by `root` and the PHP
   group.
3. Runs `php artisan cloudforge:bootstrap` as the PHP user with explicit
   credential and status paths, so a cached configuration cannot redirect them.
4. Runs `public/install.sh` with `CLOUDFORGE_BOOTSTRAP=1` when a credential was
   written.
5. Sources `agent.env` and refreshes the installation when the host already
   holds an identity.
6. Prints `cloudforge:bootstrap:status`.

The wrapper is idempotent. Re-running it updates host facts, preserves the
server ID and never creates a second bootstrap record.

The equivalent manual sequence is:

```sh
php artisan cloudforge:bootstrap
sudo env CLOUDFORGE_BOOTSTRAP=1 sh public/install.sh
```

`cloudforge:bootstrap` accepts `--name`, `--url`, `--credential-path`,
`--status-path` and `--json`. The JSON summary never contains the token.

### 3. Create the organization

Open the dashboard, register the first user if necessary, and create the
organization on the onboarding screen. Onboarding lists unclaimed bootstrap
nodes and explains that creating an organization claims the host.

If exactly one organization already exists when the agent enrolls, the
bootstrap server is claimed automatically. If several organizations exist, no
automatic claim occurs and `php artisan cloudforge:bootstrap:claim` can claim
the node explicitly:

```sh
php artisan cloudforge:bootstrap:claim --organization=<slug|uuid>
```

### Bootstrap credential files

`cloudforge:bootstrap` writes two files, both mode `0600`:

| Path | Contents |
| --- | --- |
| `/etc/cloudforge/bootstrap.env` | Shell-sourceable `CLOUDFORGE_CONTROL_PLANE_URL`, `CLOUDFORGE_SERVER_ID` and `CLOUDFORGE_ENROLLMENT_TOKEN`. Deleted by the installer after use. |
| `/etc/cloudforge/bootstrap.json` | Status document. Contains the server, machine-id, enrollment ID, purpose and expiry. Never contains the token. |

The installer refuses to source a credential file whose mode is not `0600`. It
loads the file with `set -a`, passes the token to the enrollment process through
the environment, and deletes the file after the enrollment block. The token is
never printed by the installer and is never written to `agent.env`.

The bootstrap token is scoped to one server record and, because bootstrap
enrollment requires a matching `machine_id`, to one physical host. The token has
a 30-minute default TTL and is single-use.

### Machine-id requirement

Bootstrap provisioning requires `/etc/machine-id`, falling back to
`/var/lib/dbus/machine-id`. If neither is readable, the command fails rather
than inventing an identity. On unusual hosts only, export
`CLOUDFORGE_MACHINE_ID` as a 32-character lowercase hex value before running the
bootstrap wrapper. The wrapper passes it to both `cloudforge:bootstrap` and the
installer, and the agent sends the same normalized value at enrollment. The
override is not stored in `agent.env`; export it again if the host is
re-enrolled.

## What the installer changes

The installer is deliberately explicit. On a clean host it:

1. Detects the architecture and downloads the matching archive and checksums.
2. Verifies the SHA-256 checksum.
3. Creates the `cloudforge-agent` system user if it does not exist, with the data
   directory as its home and a `nologin` shell.
4. Creates `/usr/local/bin` and `/etc/cloudforge` with mode `0755`, and the data
   directory with mode `0750`, owned by the agent user.
5. Installs the binary at `/usr/local/bin/cloudforge-agent` with mode `0755`.
6. Adds the agent user to the `docker` group when that group exists.
7. Writes `/etc/cloudforge/agent.env` with mode `0600`, owned by `root:root`.
8. Renders `/etc/systemd/system/cloudforge-agent.service` with the resolved
   install, config, data and Docker socket paths.
9. Redeems the enrollment token as the unprivileged agent user, unless a
   complete identity already exists and `CLOUDFORGE_FORCE_ENROLL` is not `1`.
10. Deletes a consumed bootstrap credential.
11. Reloads systemd, enables the unit and starts the agent.

The unit runs the agent without privileges beyond its dedicated user. It sets
`NoNewPrivileges`, `PrivateTmp`, `ProtectSystem=strict`, `ProtectHome` and a
restrictive `UMask=0077`. Writes are limited to the data directory. The Docker
socket is the only additional path granted through `ReadWritePaths`; the `-`
prefix makes systemd ignore a missing socket instead of failing the unit.

## Verify the installation

```sh
systemctl status cloudforge-agent --no-pager
journalctl -u cloudforge-agent -n 50 --no-pager
```

A successful enrollment logs the server ID, certificate fingerprint and
expiry. The dashboard server page should move from `installation_pending` to
`agent_registered` within a few seconds, then receive a heartbeat within 30
seconds.

The control plane's view can also be checked on the host:

```sh
php artisan cloudforge:bootstrap:status
php artisan cloudforge:bootstrap:status --json
```

Bootstrap mode is reserved for the control-plane host. Additional VPS nodes
should use the generated install command from the dashboard.

## Re-running and upgrades

Re-running the installer with a complete identity (`agent.key` and `agent.crt`
present) refreshes the binary and unit and skips enrollment. It still requires
`CLOUDFORGE_CONTROL_PLANE_URL` and `CLOUDFORGE_SERVER_ID`. To refresh a host
without retyping them:

```sh
sudo sh -c 'set -a; . /etc/cloudforge/agent.env; set +a; sh install.sh'
```

The agent renews its certificate automatically inside the rotation window
(30 days before expiry by default) by calling
`POST /api/agent/v1/certificates/rotate`. No reinstall is required.

To replace an agent identity:

1. Revoke the old certificate in the dashboard or API.
2. Issue a new enrollment token.
3. Run the installer with `CLOUDFORGE_FORCE_ENROLL=1`.

Replacing an identity without revoking the old certificate is not supported: the
control plane refuses to issue an enrollment token while any active certificate
exists for the server. Revocation takes effect on the old agent's next request.

## Uninstall

```sh
systemctl disable --now cloudforge-agent
rm -f /etc/systemd/system/cloudforge-agent.service
systemctl daemon-reload
rm -f /usr/local/bin/cloudforge-agent
rm -rf /etc/cloudforge /var/lib/cloudforge-agent
```

Revoke the server's certificate in the dashboard before deleting the identity
if the host will no longer be trusted. Deleting `/var/lib/cloudforge-agent`
removes the private key and is irreversible.

## Troubleshooting

| Symptom | Cause and action |
| --- | --- |
| `unsupported architecture` | Only `amd64` and `arm64` are packaged. Build from source for another architecture. |
| `checksum verification failed` | The archive and `SHA256SUMS` do not match. Check `CLOUDFORGE_DOWNLOAD_BASE_URL` and the release contents. |
| `bootstrap credential … must have mode 0600` | Fix the mode with `chmod 0600 /etc/cloudforge/bootstrap.env`, or re-run `cloudforge:bootstrap`, which writes it atomically with the correct mode. |
| `This bootstrap enrollment token is not valid for this host` | The agent reported a different `machine_id` than the bootstrap server record. Confirm `/etc/machine-id`; do not reuse a credential on another host. |
| `The enrollment token is invalid, expired, or has already been used.` | Issue a new token. Tokens are single-use and short-lived. |
| Enrollment fails with a TLS error | Install the control-plane CA with `CLOUDFORGE_CA_FILE`, or use a certificate trusted by the host. |
| `plain http is only allowed for loopback development` | Use the public HTTPS URL. |
| Agent starts but inventory has no Docker facts | Docker is not running, the socket is not a Unix socket, or the agent user cannot read it. Check the socket path and group membership, then restart the agent. |
| Requests fail with signature or timestamp errors | The host clock is outside the 300-second skew window. Enable time synchronization. |

More detail on the wire protocol is in [protocol.md](protocol.md). The
control-plane deployment procedure is in
[../deployment/control-plane.md](../deployment/control-plane.md).
