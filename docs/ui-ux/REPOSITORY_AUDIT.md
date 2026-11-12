# Frontend repository audit

**Audit date:** 2026-11-12
**Scope:** `apps/control-plane/resources`, Inertia page props, web routes and
existing frontend tests.

This audit records the state before the UI/UX implementation. It is not a claim
that every product workflow exists.

## Existing implementation

- Laravel 13, Inertia v3 and React 19 with a single authenticated `AppLayout`.
- Instrument Sans for UI type and JetBrains Mono for technical values.
- Tokenised light/dark palette in `resources/css/app.css`. The HTML root was
  hard-coded to dark mode; light mode is now the default with a persisted toggle.
- Reusable components: buttons, fields, selects, textareas, status badges,
  confirmation dialogs, copy fields, empty states, flash messages, pagination,
  sparklines and an SVG icon set.
- Pages: dashboard, onboarding, projects, project detail, servers, server
  onboarding, server detail, team, API tokens, audit log and organization
  settings, plus login/register.
- Managed PostgreSQL/Redis resources are exposed inside the project page.
- Real backend data is used throughout; there are no seeded dashboard metrics.

## Strengths to preserve

- The warm neutral light palette and teal/forge identity are already distinctive.
- Forms, status badges and empty states are consistent enough to extend rather
  than replace.
- Server, project and resource actions are backed by real routes and policies.
- All resource credentials are returned only through backend-controlled flashes.

## Inconsistencies and gaps

| Area | Finding |
| --- | --- |
| Navigation | Flat list with no workspace/infrastructure/management grouping. |
| Header | No breadcrumbs, account menu, theme control or mobile close affordance. |
| Theme | Dark tokens existed but the root was fixed to dark; no user control. |
| Databases | Resources could only be seen inside one project; no organization-wide database view or filters. |
| Dashboard | Real data, but no managed-database summary, attention queue or database section. |
| Shared UI | No page-section, stat-card, skeleton or reusable credentials panel abstractions. |
| Tables | Inconsistent filter/sort affordances and mobile treatment across list pages. |
| Loading | No skeleton primitives; Inertia navigations rely on the global progress bar. |
| Accessibility | Mobile drawer lacked a close button and Escape handling; account menu did not exist. |
| Applications | No backend models, routes or deployment lifecycle exist. |
| Deployments | No backend records, logs or streaming exist. |
| Domains, backups, networking | No backend contracts exist. |
| MySQL | Resource enum and agent support exist only for PostgreSQL and Redis. |

## Backend capabilities that must not be invented

- Application records, repository connections, framework detection and deploy
  operations.
- Deployment history, logs, health checks and rollback.
- Domains, DNS verification and TLS state.
- Backups, restores and retention.
- Networking topology and private networks.
- MySQL provisioning.
- Application-to-resource attachment.

Those screens are intentionally absent. A static "coming soon" application or
deployment dashboard would violate the no-fake-features rule.

## Implemented in this pass

1. Semantic design tokens, theme switching, skeleton primitive and card elevation.
2. Grouped responsive sidebar with collapse, org switcher, breadcrumbs, theme
   control and account menu.
3. Real-data dashboard with fleet health, attention queue, managed databases and
   recent activity.
4. Organization-wide Databases view with real PostgreSQL/Redis resources,
   search, engine/status filters, sorting, lifecycle actions and audited
   credential viewing.
5. Projects listing with real database/environment/server counts, search,
   sorting and a compact creation panel.
6. Project workspace with Overview, Databases, Environments, Activity and
   Settings tabs, environment cards, real activity and lifecycle actions.
7. Shared `PageSection`, `StatCard`, `ResourceCredentials`, `Breadcrumb`,
   `ThemeToggle` and `UserMenu` components.
8. Documentation and tests for the new pages.

## Risks

- The default theme is now light to match the approved direction; dark mode is
  user-selectable. Further contrast testing is still required with a browser.
- Resource rows depend on the agent having a current Docker heartbeat.
- No browser end-to-end harness is installed; verification is Inertia response
  tests, the production build and manual review.
- Application/deployment UX remains blocked on backend implementation.
