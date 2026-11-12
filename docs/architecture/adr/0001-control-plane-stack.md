# ADR 0001: Control-plane stack

- Status: Accepted
- Date: 2026-11-12
- Phase: 1

## Context

CloudForge needs a multi-tenant control plane that serves a dashboard, a
machine API and an agent protocol. It must be deployable on a single host by an
operator who owns the infrastructure, with a codebase that can grow through the
later deployment, database and scaling phases.

## Decision

Use:

- **Laravel 13 on PHP 8.4** for the application runtime. It provides mature
  authentication, validation, queues, scheduling, policies and database
  migrations without a bespoke framework.
- **Inertia v3 with React 19** for the dashboard. Pages are server-routed
  Laravel responses, so authorization and data loading stay in PHP and there is
  no second REST layer to maintain for the browser.
- **PostgreSQL** as the production database. It provides row locking, JSON
  columns and transactional guarantees needed by enrollment and the command
  queue.
- **Redis** for sessions, cache, queues and the request-nonce replay cache.
- **SQLite** for the automated test suite, with database, cache, session and
  queue drivers set to in-memory or synchronous equivalents. Tests exercise the
  same Eloquent code paths and do not require external services.
- **UUID primary keys** for externally visible records.
- Domain-oriented application code under `app/Domains`, with thin controllers
  and service classes for multi-step operations.

## Consequences

Positive:

- One deployable artifact serves both the dashboard and the APIs.
- Authorization is centralized in Laravel policies and organization role
  checks, which makes tenant isolation testable.
- The test suite runs without PostgreSQL or Redis, keeping local feedback fast.
- A future worker process can consume the same queue without changing the
  domain model.

Negative:

- SQLite and PostgreSQL differ in locking and JSON behavior. Tests that rely on
  `lockForUpdate` prove application structure, not PostgreSQL concurrency.
- Inertia couples the dashboard to the Laravel release cycle.
- Running PHP-FPM, a queue worker and the scheduler adds operational work
  compared with a single-process application server.

## Alternatives considered

- **Separate API and SPA projects.** Rejected for Phase 1 because it duplicates
  authentication, validation and authorization and adds a second deployment.
- **MySQL instead of PostgreSQL.** Viable, but PostgreSQL is the target for
  later phases that need richer indexing and transactional DDL.
- **MongoDB or another document store.** Rejected because the domain is
  relational and auditability benefits from foreign keys and transactions.
- **MySQL/SQLite for production.** SQLite is not appropriate for concurrent
  multi-worker production writes.
