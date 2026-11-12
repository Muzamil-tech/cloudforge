# Agent protocol

**Protocol version:** 1.0  
**Base path:** `/api/agent/v1`  
**Content type:** `application/json`

The agent always initiates the connection. There is no inbound port, no
server-initiated RPC and no shell channel. The control plane authenticates each
request from the agent's client certificate and an RSA signature over the
request.

## Transport and trust

- HTTPS is required. The agent rejects HTTP unless the host is `localhost`,
  `127.0.0.1` or `::1`.
- TLS 1.2 or newer is required by the agent.
- The agent trusts the system trust store plus an optional PEM bundle supplied
  through `CLOUDFORGE_CA_FILE` or `--ca-file`.
- The agent presents its client certificate at the TLS layer and also signs
  every authenticated request.
- If a TLS terminator ends mutual TLS before Laravel, it can forward the
  certificate serial. Enable
  `CLOUDFORGE_REQUIRE_CLIENT_CERT_HEADER=true` and configure
  `CLOUDFORGE_CLIENT_CERT_SERIAL_HEADER` (default `X-Client-Cert-Serial`) to
  make the application reject a request whose forwarded serial or fingerprint
  does not match the registered certificate.

## Identity

Enrollment creates an RSA-2048 key and a certificate signing request (CSR). The
CSR common name must be the CloudForge server UUID. The control plane signs the
CSR with its agent certificate authority and returns:

- the client certificate in PEM form,
- its serial number,
- its lowercase SHA-256 fingerprint,
- its expiry,
- the CA certificate.

The agent verifies the returned leaf against the returned CA and checks the
certificate common name against the expected server ID before writing the
identity. Identity files are written atomically; a partial identity is never
used.

| File | Mode | Purpose |
| --- | --- | --- |
| `<dataDir>/agent.key` | `0600` | RSA private key |
| `<dataDir>/agent.crt` | `0644` | Client certificate |
| `<dataDir>/ca.crt` | `0644` | Control-plane CA certificate |

The default data directory is `/var/lib/cloudforge-agent`.

## Enrollment

`POST /api/agent/v1/enroll` is the only endpoint that does not require a
signature or a client certificate. It is rate limited to 10 requests per minute
per IP address.

### Request

```json
{
  "token": "cfe_…",
  "csr": "-----BEGIN CERTIFICATE REQUEST-----\n…",
  "protocol_version": "1.0",
  "agent_version": "1.0.0",
  "hostname": "edge-1.example.com",
  "operating_system": "Ubuntu 24.04.1 LTS",
  "architecture": "amd64",
  "docker_version": "27.3.1",
  "machine_id": "8f2c1d4e5a6b7c8d9e0f1a2b3c4d5e6f"
}
```

| Field | Required | Notes |
| --- | --- | --- |
| `token` | Yes | Single-use enrollment token, at most 255 characters. |
| `csr` | Yes | PEM CSR, at most 16 KiB. Subject CN must equal the server UUID. |
| `protocol_version` | No | Informational; the control plane responds with its own version. |
| `agent_version` | Yes | At most 64 characters. |
| `hostname` | Yes | At most 255 characters. Stored on the server. |
| `operating_system` | No | At most 255 characters. |
| `architecture` | No | At most 64 characters. |
| `docker_version` | No | At most 128 characters. |
| `machine_id` | No for installation tokens, required for bootstrap tokens | Normalized host machine-id. |

A bootstrap enrollment token is bound to the `machine_id` recorded by
`cloudforge:bootstrap`. The value must match exactly. A missing or different
value returns HTTP `422` with an error under `machine_id`, and the token is not
consumed. Installation tokens issued from the dashboard or API are not bound to
a machine-id; they are bound to the server UUID the operator selected.

### Response

HTTP `201`:

```json
{
  "protocol_version": "1.0",
  "server": {
    "id": "8a7f5f1e-2a5e-4a2f-9c1b-6f0d1e2a3b4c",
    "name": "edge-1",
    "hostname": "edge-1.example.com"
  },
  "certificate": {
    "pem": "-----BEGIN CERTIFICATE-----\n…",
    "serial": "1234567890",
    "fingerprint_sha256": "…",
    "expires_at": "2027-11-12T10:00:00+00:00"
  },
  "ca_certificate": "-----BEGIN CERTIFICATE-----\n…",
  "heartbeat_interval_seconds": 30,
  "command_poll_interval_seconds": 5
}
```

The returned intervals are informational in protocol 1.0; the agent keeps its
locally configured cadence.

### Failure

HTTP `422`:

```json
{
  "message": "Enrollment failed.",
  "code": "enrollment_failed",
  "errors": {
    "token": ["The enrollment token is invalid, expired, or has already been used."]
  }
}
```

Invalid, expired, reused and revoked tokens return the same token message so the
endpoint does not reveal which condition applied. An invalid CSR returns an
error under `csr`.

### Token lifecycle

- Tokens are random values prefixed with `cfe_`.
- Only a SHA-256 hash is stored.
- A token is consumed inside a database transaction with a row lock, so two
  concurrent redemptions cannot both succeed.
- Consuming one token marks every other unconsumed token for the same server as
  consumed.
- Installation tokens expire after 15 minutes by default. Bootstrap tokens
  expire after 30 minutes by default.
- A successful enrollment sets the server to `agent_registered` and records the
  host facts supplied by the agent.

### Bootstrap claim

A bootstrap server starts with `organization_id = NULL`. It is claimed by:

- creating an organization, which claims all unclaimed bootstrap servers, or
- enrolling while exactly one organization exists, which claims the server
  automatically, or
- `php artisan cloudforge:bootstrap:claim` with an explicit organization.

A bootstrap enrollment token cannot be issued for an already-claimed server.

## Authenticated requests

Every request except enrollment carries four headers:

```text
X-CloudForge-Fingerprint  lowercase SHA-256 fingerprint of the DER client certificate
X-CloudForge-Timestamp    Unix seconds
X-CloudForge-Nonce        base64url nonce, 16–128 characters of [A-Za-z0-9_-]
X-CloudForge-Signature    standard base64 RSA-SHA256 signature
```

The signature covers this exact canonical string:

```text
METHOD \n request-uri \n timestamp \n nonce \n sha256-hex(raw-body)
```

- `METHOD` is uppercase, for example `GET` or `POST`.
- `request-uri` is the path plus query string, for example
  `/api/agent/v1/commands/next?wait=25`. It is not the absolute URL.
- `sha256-hex` is the lowercase hex SHA-256 of the raw request body. The body of
  a bodyless request hashes the empty string.
- The canonical string is signed with RSA PKCS#1 v1.5 and SHA-256, then encoded
  with standard base64.

Validation order:

1. All four headers must be present and decodable.
2. The timestamp must be decimal Unix seconds within a 300-second skew.
3. The nonce must match the allowed character set and length.
4. The fingerprint must resolve to a certificate that is not revoked and not
   expired.
5. When trusted-terminator checking is enabled, the forwarded serial or
   fingerprint must match the certificate.
6. The nonce must not have been used before. Nonces are cached for twice the
   skew window (600 seconds by default).
7. The signature must verify against the public key in the certificate.

Failures return HTTP `401`. Rate limits are 300 requests per minute per
fingerprint. The command long-poll occupies one request for up to 25 seconds,
so pollers should allow for that when sizing proxies and timeouts.

## Heartbeat

`POST /api/agent/v1/heartbeat`

```json
{
  "agent_version": "1.0.0",
  "docker_available": true,
  "docker_version": "27.3.1",
  "uptime_seconds": 86400,
  "load_average": [0.42, 0.55, 0.61]
}
```

`uptime_seconds`, `load_average` and `docker_version` are optional. The control
plane updates `last_heartbeat_at`, clears `unreachable`, and returns:

```json
{
  "status": "ok",
  "server_status": "active",
  "heartbeat_interval_seconds": 30,
  "server_time": "2026-11-12T10:00:00+00:00"
}
```

Heartbeat state changes emit audit events. A server whose last heartbeat is
older than 90 seconds is marked `unreachable` by the
`servers:mark-unreachable` schedule. The next heartbeat restores the previous
operational status or a status derived from Docker availability.

## Inventory

`POST /api/agent/v1/inventory` replaces the stored snapshot.

```json
{
  "hostname": "edge-1.example.com",
  "operating_system": "Ubuntu 24.04.1 LTS",
  "architecture": "amd64",
  "cpu_cores": 4,
  "total_memory_bytes": 8589934592,
  "total_disk_bytes": 107374182400,
  "docker_version": "27.3.1",
  "agent_version": "1.0.0",
  "collected_at": "2026-11-12T10:00:00Z",
  "docker": {
    "available": true,
    "version": "27.3.1",
    "api_version": "1.47",
    "storage_driver": "overlay2",
    "rootless": false
  },
  "capabilities": [
    {"name": "docker.engine", "version": "27.3.1", "metadata": {"api_version": "1.47", "os": "linux", "arch": "amd64"}},
    {"name": "docker.storage_driver", "version": "overlay2"}
  ]
}
```

Response:

```json
{
  "status": "ok",
  "server_status": "ready",
  "inventory_recorded_at": "2026-11-12T10:00:01+00:00"
}
```

The Docker summary is intentionally limited to facts obtained from
`GET /version` and `GET /info` over a Unix socket: daemon version, API version,
storage driver, rootless status and Swarm state. The agent does not enumerate
containers, images, networks or volumes for inventory. Managed resource
operations are a separate typed path described below.

## Metrics

`POST /api/agent/v1/metrics`

```json
{
  "points": [
    {
      "recorded_at": "2026-11-12T10:00:00Z",
      "cpu_usage": 12.5,
      "memory_usage": 41.2,
      "disk_usage": 63.0,
      "memory_used_bytes": 3543348019,
      "disk_used_bytes": 67645734912,
      "network_in_bytes": 123456,
      "network_out_bytes": 654321,
      "metadata": {}
    }
  ]
}
```

- `points` must contain between 1 and 240 samples.
- Percentages are between 0 and 100.
- Byte counters are non-negative integers.
- Optional fields are omitted when the platform cannot supply them; the agent
  uses pointers so an unsupported value is absent rather than a false zero.
- The agent buffers up to 240 samples when a request fails and retries them.
- Samples are retained for 14 days by default.

Response:

```json
{"status": "ok", "recorded": 1}
```

## Commands

Commands are persisted before delivery. The lifecycle is:

```text
pending → acknowledged → completed | failed
        ↘ cancelled
        ↘ expired
```

### Poll

`GET /api/agent/v1/commands/next?wait=25`

`wait` is clamped to 0–25 seconds. The endpoint long-polls in one-second
increments and returns HTTP `204` with no body when no command is available.
When a command is available it returns:

```json
{
  "command": {
    "id": "b6b8c1e0-3b0e-4d63-8f6e-2a8a1f0c9d21",
    "operation": "server.inspect",
    "payload": {"reason": "verify Docker inventory"},
    "issued_at": "2026-11-12T10:00:00+00:00",
    "expires_at": "2026-11-12T10:05:00+00:00",
    "attempt": 1
  }
}
```

`attempt` starts at 1 and increments on every delivery. Pending commands are
delivered immediately. Acknowledged commands are redelivered only when
`acknowledged_at` is older than one minute, and only while the command has not
expired. Expired commands are moved to `expired` before the query.

Responses include `Cache-Control: no-store`.

### Acknowledge

`POST /api/agent/v1/commands/{id}/ack`

No body is required. Acknowledging a terminal command is a no-op. The response
is:

```json
{"status": "acknowledged", "command_id": "b6b8c1e0-3b0e-4d63-8f6e-2a8a1f0c9d21"}
```

The agent acknowledges before executing so the control plane can distinguish
"delivered but crashed" from "never delivered".

### Report a result

`POST /api/agent/v1/commands/{id}/result`

```json
{
  "status": "completed",
  "result": {"docker_available": true},
  "duration_ms": 240
}
```

`status` must be `completed` or `failed`. `error_message` is optional, at most
2000 characters, and is stored when the status is `failed`. `duration_ms` must
be non-negative. The response echoes the terminal status:

```json
{"status": "completed", "command_id": "b6b8c1e0-3b0e-4d63-8f6e-2a8a1f0c9d21"}
```

Reporting a result for a terminal command is a no-op, which makes retries safe.
Command ownership is scoped to the authenticated server; a command ID belonging
to another server returns `404`.

### Operations

| Operation | Default TTL | Effect |
| --- | --- | --- |
| `server.inspect` | 300 seconds | Collect host and Docker inventory. |
| `server.health` | 300 seconds | Report agent and Docker health. |
| `metrics.collect` | 300 seconds | Read one metric sample. |
| `agent.restart` | 60 seconds | Report the result, then exit so systemd restarts the process. The result is best effort. |
| `resource.create` | 900 seconds | Pull an allowlisted image, create a persistent volume and container, start it, and return connection details once. |
| `resource.start` | 120 seconds | Start an existing managed container. |
| `resource.stop` | 120 seconds | Stop an existing managed container. |
| `resource.delete` | 180 seconds | Remove the managed container and its volume. |
| `resource.inspect` | 60 seconds | Read the container state and published port. |

All operations accept an optional `payload.reason` string of at most 255
characters. Resource operations use a strict payload:

```json
{
  "resource_id": "01a1d058-8612-7209-9082-03adf0d11976",
  "resource_type": "postgresql",
  "container_name": "cloudforge-01a1d058-8612-7209-9082-03adf0d11976",
  "volume_name": "cloudforge-01a1d058-8612-7209-9082-03adf0d11976-data"
}
```

`resource.start`, `resource.stop` and `resource.inspect` require only
`resource_id` and `container_name`. `resource.delete` also requires
`volume_name`. The agent rejects malformed IDs or names, unsupported resource
types and any attempt to supply an image, command, mount or port mapping.

The resource result for `resource.create` contains the container ID, loopback
host, published port, username, database, status and a generated password. The
control plane encrypts the password in the resource record and removes it from
the generic command result. The agent's durable command store also omits the
password. There is no generic shell operation and no arbitrary Docker API
operation. An unknown operation is reported as failed and is never executed.

### Agent-side deduplication

The agent keeps terminal outcomes in `<dataDir>/commands.json` with mode `0600`.
If the same command is delivered again, the agent reports the stored outcome
instead of executing the operation twice. If the previous process stopped after
writing an `executing` marker, the agent reports that command as failed rather
than repeating an operation whose side effects are unknown.

## Certificate rotation

`POST /api/agent/v1/certificates/rotate`

```json
{"csr": "-----BEGIN CERTIFICATE REQUEST-----\n…"}
```

The request is authenticated with the current certificate. The CSR subject must
again be the server UUID. Response:

```json
{
  "certificate": {
    "pem": "-----BEGIN CERTIFICATE-----\n…",
    "serial": "987654321",
    "fingerprint_sha256": "…",
    "expires_at": "2028-11-12T10:00:00+00:00"
  },
  "ca_certificate": "-----BEGIN CERTIFICATE-----\n…"
}
```

The agent checks its certificate every 12 hours and rotates inside the window
(30 days before expiry by default). The new identity is verified and written
atomically, then swapped into the live HTTP client. The control plane revokes
every other active certificate for the server with reason `rotated` in the same
transaction, so only the new certificate remains active.

An expired certificate is rejected on every authenticated request. The agent
logs an error and requires re-enrollment; it keeps running and retrying rather
than exiting.

## Error format

Validation and authentication failures use the standard Laravel JSON shape:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field": ["First validation error."]
  }
}
```

| Status | Meaning |
| --- | --- |
| `200` | Success |
| `201` | Certificate issued |
| `204` | Long poll elapsed with no command |
| `401` | Missing, invalid, expired or revoked agent identity; bad signature, timestamp or nonce |
| `404` | Command does not belong to the authenticated server |
| `422` | Validation or enrollment failure |
| `429` | Rate limit exceeded |

Enrollment failures add `code: "enrollment_failed"`. Authentication failures do
not reveal whether a fingerprint exists, whether a certificate is revoked, or
whether a nonce was replayed.

## Versioning

`protocol_version` is exchanged at enrollment and is `1.0` in this release. The
agent verifies that the response version matches the version it sent. Additive
JSON fields are compatible; removing or changing the meaning of a field requires
a protocol version change.

## Related documents

- [Agent installation](installation.md)
- [Control-plane deployment](../deployment/control-plane.md)
- [API reference](../api/README.md)
- [Architecture overview](../architecture/overview.md)
