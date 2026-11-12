# ADR 0004: Organization-scoped authorization

- Status: Accepted
- Date: 2026-11-12
- Phase: 1

## Context

CloudForge is multi-tenant. Every project, environment, server, audit entry and
API token belongs to exactly one organization. The same user may belong to
several organizations with different roles. A single missing scope check would
expose another tenant's infrastructure.

## Decision

- Every tenant-owned table has a non-null `organization_id` foreign key.
- Web routes resolve the active organization from the session through
  `OrganizationContext`; the context falls back to the user's first active
  membership. All resource lookups start from the active organization’s
  relationship, never from a global model lookup.
- API requests resolve the organization from the Bearer token. There is no
  client-supplied organization header.
- Roles are `owner`, `admin`, `developer`, `operator` and `viewer`, with explicit
  capability methods for organization management, member management,
  infrastructure management, deployment and operations.
- Laravel policies enforce capabilities on the web surface. API token abilities
  (`servers:read`, `servers:write`, `commands:write`, `projects:read`,
  `projects:write`, `audit:read`) enforce the API surface. A token is always
  bounded by its organization even when it has a broad ability.
- Cross-organization web access returns `403`; cross-organization API access
  returns `404`. The API deliberately does not confirm that another tenant’s
  resource exists.
- Audit records are written inside the same organization scope and include the
  actor, resource, IP address and metadata.

## Consequences

Positive:

- The tenant boundary is explicit in schema, queries and authorization.
- Tests can assert isolation for every lifecycle.
- API tokens cannot escape their organization by guessing a UUID.

Negative:

- Every new tenant-owned model and endpoint must remember the organization
  scope. Code review and isolation tests are mandatory.
- Global administrative views are intentionally absent in Phase 1.
- The session can only target one active organization at a time.

## Alternatives considered

- **Global resource IDs with per-user ACLs.** More flexible, but harder to
  audit and easier to misconfigure. Rejected for Phase 1.
- **Organization ID supplied by the API client.** Rejected: a token must not be
  able to select a different tenant.
- **Role checks only in the UI.** Rejected: the React layer is not a security
  boundary.
