# CloudForge API reference

The control plane exposes two JSON APIs:

| Surface | Prefix | Authentication |
| --- | --- | --- |
| Organization API | `/api/v1` | `Authorization: Bearer cf_…` |
| Agent protocol | `/api/agent/v1` | RSA request signature plus client certificate |

This document covers the organization API. The agent wire protocol is documented
in [docs/agent/protocol.md](../agent/protocol.md).

## Conventions

- Requests and responses use `Content-Type: application/json`.
- All identifiers are UUIDs.
- Timestamps are ISO 8601 strings in UTC.
- Successful single-resource responses are wrapped in `data`.
- Collections are paginated with `data`, `links` and `meta` keys. Use `?page=N`.
- Validation failures return `422` with Laravel’s `message` and `errors` object.
- Rate limit: 120 requests per minute per API token. Responses include the
  standard `X-RateLimit-*` headers and return `429` when exceeded.

### Errors

```json
{
  "message": "A CloudForge organization API token is required.",
  "code": "unauthenticated"
}
```

```json
{
  "message": "This API token does not have the required ability.",
  "code": "forbidden",
  "required_ability": "servers:write"
}
```

| Status | Meaning |
| --- | --- |
| `401` | Missing, malformed, expired or revoked token |
| `403` | Token lacks the required ability |
| `404` | Resource does not exist in the token’s organization |
| `422` | Validation failed |
| `429` | Rate limit exceeded |

Cross-organization access always returns `404`, including for resources that
exist in another tenant.

## Authentication

Create a token in **API tokens** in the dashboard. The plaintext value is shown
once and is prefixed with `cf_`. The control plane stores only its SHA-256 hash.
Tokens may expire after 1–365 days.

```http
Authorization: Bearer cf_example_token_value
Accept: application/json
```

### Abilities

| Ability | Grants |
| --- | --- |
| `servers:read` | List servers, view a server, list commands and certificates |
| `servers:write` | Create, update and delete servers; issue enrollment tokens; revoke certificates |
| `commands:write` | Issue and cancel agent commands |
| `projects:read` | List and view projects |
| `projects:write` | Create, update and delete projects; add environments |
| `audit:read` | Read the organization audit log |

A token can hold several abilities. The wildcard `*` and suffix wildcards such
as `servers:*` are supported by the model, but the dashboard issues explicit
abilities only.

`servers:write` does not imply `servers:read`, and `commands:write` does not
imply `servers:read`. Request each ability the integration needs.

## Token introspection

### `GET /api/v1/me`

Returns the organization and token metadata associated with the credential.

```json
{
  "organization": {
    "id": "0f3f0f4e-6f1f-4e57-9b6f-6a1c0f0f9a11",
    "name": "Acme",
    "slug": "acme"
  },
  "token": {
    "id": "e9d6a3c2-0b4c-4f2c-9d47-3f9b4e2a1c88",
    "name": "CI",
    "abilities": ["servers:read", "commands:write"],
    "expires_at": "2027-01-01T00:00:00+00:00",
    "last_used_at": "2026-11-12T10:15:00+00:00"
  }
}
```

## Servers

### `GET /api/v1/servers`

Lists servers in the token’s organization, newest first, 50 per page.

```json
{
  "data": [
    {
      "id": "8a7f5f1e-2a5e-4a2f-9c1b-6f0d1e2a3b4c",
      "name": "edge-1",
      "hostname": "edge-1.example.com",
      "public_ip": "203.0.113.10",
      "private_ip": "10.0.0.10",
      "region": "fsn1",
      "operating_system": "Ubuntu 24.04",
      "architecture": "amd64",
      "cpu_cores": 4,
      "total_memory_bytes": 8589934592,
      "total_disk_bytes": 161061273600,
      "docker_version": "27.3.1",
      "agent_version": "1.0.0",
      "status": "active",
      "status_label": "Active",
      "roles": ["application"],
      "capabilities": [
        {
          "name": "docker.engine",
          "version": "27.3.1",
          "metadata": {"api_version": "1.47", "os": "linux", "arch": "amd64"}
        },
        {
          "name": "docker.storage_driver",
          "version": "overlay2",
          "metadata": null
        }
      ],
      "last_heartbeat_at": "2026-11-12T10:15:00+00:00",
      "last_inventory_at": "2026-11-12T10:10:00+00:00",
      "created_at": "2026-11-01T09:00:00+00:00",
      "updated_at": "2026-11-12T10:15:00+00:00"
    }
  ],
  "links": {"first": "…", "last": "…", "prev": null, "next": null},
  "meta": {"current_page": 1, "last_page": 1, "per_page": 50, "total": 1}
}
```

### `POST /api/v1/servers`

Requires `servers:write`.

```json
{
  "name": "edge-2",
  "hostname": "edge-2.example.com",
  "public_ip": "203.0.113.11",
  "private_ip": "10.0.0.11",
  "region": "fsn1",
  "roles": ["application", "edge"]
}
```

`name` is required and unique within the organization among non-deleted servers.
`hostname` is required. `public_ip` and `private_ip` must be valid IP addresses.
Roles are `control`, `application`, `database`, `build`, `edge`, `storage` and
`general`; the default is `general`. Returns `201` with the server in `data`.

### `GET /api/v1/servers/{server}`

Returns the server, its certificate history (newest first) and the 25 most recent
commands.

```json
{
  "data": {"id": "…", "name": "edge-1", "status": "active", "…": "…"},
  "certificates": [
    {
      "serial": "4A1B2C3D",
      "fingerprint_sha256": "c0ffee…",
      "issued_at": "2026-11-01T09:05:00+00:00",
      "expires_at": "2027-11-01T09:05:00+00:00",
      "revoked_at": null,
      "revocation_reason": null
    }
  ],
  "commands": [
    {
      "id": "b6b8c1e0-3b0e-4d63-8f6e-2a8a1f0c9d21",
      "operation": "server.health",
      "operation_label": "Check server health",
      "payload": {"reason": "manual check"},
      "status": "completed",
      "status_label": "Completed",
      "attempts": 1,
      "issued_at": "2026-11-12T10:00:00+00:00",
      "expires_at": "2026-11-12T10:05:00+00:00",
      "acknowledged_at": "2026-11-12T10:00:02+00:00",
      "completed_at": "2026-11-12T10:00:03+00:00",
      "result": {"agent": "ok", "docker_available": true},
      "error_message": null
    }
  ]
}
```

### `PATCH /api/v1/servers/{server}`

Requires `servers:write`. Accepts any subset of `name`, `hostname`,
`public_ip`, `private_ip` and `region`. Returns the updated server in `data`.

### `DELETE /api/v1/servers/{server}`

Requires `servers:write`. Revokes active certificates with reason
`server_deleted`, soft-deletes the server and returns:

```json
{"deleted": true}
```

### `POST /api/v1/servers/{server}/enrollment`

Requires `servers:write`. Creates a single-use enrollment token valid for 15
minutes by default.

```json
{
  "enrollment_token": "cfe_…",
  "expires_at": "2026-11-12T10:30:00+00:00",
  "install_command": "curl -fsSL https://cloudforge.example.com/install.sh | sudo env CLOUDFORGE_ENROLLMENT_TOKEN='…' CLOUDFORGE_CONTROL_PLANE_URL='https://cloudforge.example.com' CLOUDFORGE_SERVER_ID='…' sh"
}
```

Returns `201`. The token is shown once and cannot be retrieved again.

Installation tokens are bound to the server UUID. Bootstrap tokens created by
`php artisan cloudforge:bootstrap` are additionally bound to the host
`machine_id` reported at enrollment and have a 30-minute default TTL. A bootstrap
server starts unclaimed (`organization_id = NULL`); it is claimed when an
organization is created, when it enrolls while exactly one organization exists,
or explicitly with `php artisan cloudforge:bootstrap:claim`. A bootstrap
enrollment token cannot be issued for an already-claimed server.

The agent enrollment request and its optional `machine_id` field are documented
in [docs/agent/protocol.md](../agent/protocol.md#enrollment).

### `POST /api/v1/servers/{server}/certificates/revoke`

Requires `servers:write`.

```json
{"reason": "host decommissioned"}
```

Revokes every active certificate for the server. The agent’s next request fails
with `401 agent_authentication_failed`. Returns the number revoked:

```json
{"revoked": 1}
```

## Agent commands

### `GET /api/v1/servers/{server}/commands`

Requires `servers:read`. Lists commands newest first, 50 per page.

### `POST /api/v1/servers/{server}/commands`

Requires `commands:write`. Queues one allowlisted operation.

```json
{
  "operation": "server.inspect",
  "payload": {"reason": "verify Docker inventory"},
  "idempotency_key": "deploy-2026-11-12-001"
}
```

`operation` must be one of `server.inspect`, `server.health`, `metrics.collect`
or `agent.restart`. Resource operations (`resource.*`) are issued only by the
resource domain, which enforces project ownership and roles; they are rejected
by this generic command endpoint. The optional `payload.reason` is limited to 255
characters.
`idempotency_key` may also be sent as the `Idempotency-Key` header. Reusing a key
in the same organization returns the existing command instead of creating a
duplicate.

Returns `202` with the command in `data`. The command is delivered when the agent
next polls; `202` does not mean it has executed.

```json
{
  "data": {
    "id": "b6b8c1e0-3b0e-4d63-8f6e-2a8a1f0c9d21",
    "operation": "server.inspect",
    "operation_label": "Inspect server",
    "payload": {"reason": "verify Docker inventory"},
    "status": "pending",
    "status_label": "Pending",
    "attempts": 0,
    "issued_at": "2026-11-12T10:00:00+00:00",
    "expires_at": "2026-11-12T10:05:00+00:00",
    "acknowledged_at": null,
    "completed_at": null,
    "result": null,
    "error_message": null
  }
}
```

### `POST /api/v1/servers/{server}/commands/{command}/cancel`

Requires `commands:write`. Cancels a pending or acknowledged command. Cancelling
a terminal command is a no-op and returns its current state.

## Projects

### `GET /api/v1/projects`

Requires `projects:read`. Lists projects with their environments, 50 per page.

```json
{
  "data": [
    {
      "id": "2f6b1d0e-1c3a-4f5e-8a7b-9c0d1e2f3a4b",
      "name": "Storefront",
      "slug": "storefront",
      "description": "Customer-facing Laravel application",
      "environments": [
        {"id": "…", "name": "Production", "slug": "production", "type": "production"}
      ],
      "created_at": "2026-11-01T09:00:00+00:00",
      "updated_at": "2026-11-01T09:00:00+00:00"
    }
  ],
  "links": {"…": "…"},
  "meta": {"current_page": 1, "last_page": 1, "per_page": 50, "total": 1}
}
```

### `POST /api/v1/projects`

Requires `projects:write`. Creates a project and its default `Production`
environment.

```json
{"name": "Storefront", "description": "Customer-facing Laravel application"}
```

Returns `201` with the project in `data`. Slugs are generated and made unique
within the organization.

### `GET /api/v1/projects/{project}`

Requires `projects:read`. Returns one project with environments.

### `PATCH /api/v1/projects/{project}`

Requires `projects:write`. Accepts `name` and/or `description`. Renaming
regenerates the slug when the name changes.

### `DELETE /api/v1/projects/{project}`

Requires `projects:write`. Deletes the project and returns `{"deleted": true}`.

### `POST /api/v1/projects/{project}/environments`

Requires `projects:write`.

```json
{"name": "Staging", "type": "staging"}
```

Types are `development`, `staging` and `production`. Names are slugged and must
be unique within the project. Returns `201`:

```json
{
  "data": {
    "id": "…",
    "name": "Staging",
    "slug": "staging",
    "type": "staging"
  }
}
```

If the slug already exists, returns `422` with
`code: "environment_exists"`.

## Applications

Application deployment is a web/Inertia surface in this slice. The public API
does not yet expose application mutations. The control plane issues typed
`application.*` commands through the same durable agent queue; see
`docs/agent/protocol.md` for the payloads, TTLs and result shapes.

---

## Audit log

### `GET /api/v1/audit-logs`

Requires `audit:read`. Returns organization audit entries newest first, 100 per
page. Filter with `?action=server.enrolled`.

```json
{
  "data": [
    {
      "id": "5a0f2c1d-7e8b-4a6c-9d0e-1f2a3b4c5d6e",
      "action": "server.enrolled",
      "resource_type": "server",
      "resource_id": "8a7f5f1e-2a5e-4a2f-9c1b-6f0d1e2a3b4c",
      "actor": null,
      "ip_address": null,
      "metadata": {"hostname": "edge-1.example.com", "agent_version": "1.0.0"},
      "created_at": "2026-11-01T09:05:00+00:00"
    }
  ],
  "links": {"…": "…"},
  "meta": {"current_page": 1, "last_page": 1, "per_page": 100, "total": 1}
}
```

Agent-originated events have `actor: null` and are identified as `system` in the
dashboard. A token-created change is attributed to the user who created the
token, not to the token itself.

## Agent protocol summary

| Endpoint | Purpose |
| --- | --- |
| `POST /api/agent/v1/enroll` | Redeem a single-use token and obtain a certificate |
| `POST /api/agent/v1/heartbeat` | Report liveness and Docker availability |
| `POST /api/agent/v1/inventory` | Replace the stored host and Docker inventory |
| `POST /api/agent/v1/metrics` | Submit up to 240 metric points |
| `POST /api/agent/v1/certificates/rotate` | Replace the client certificate |
| `GET /api/agent/v1/commands/next` | Long-poll for one command |
| `POST /api/agent/v1/commands/{id}/ack` | Acknowledge delivery |
| `POST /api/agent/v1/commands/{id}/result` | Report a terminal outcome |

Full headers, payloads and error codes are in
[docs/agent/protocol.md](../agent/protocol.md).
