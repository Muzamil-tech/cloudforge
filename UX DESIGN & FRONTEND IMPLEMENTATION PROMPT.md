# CLOUDFORGE — MASTER UI/UX DESIGN & FRONTEND IMPLEMENTATION PROMPT

## 1. ROLE AND RESPONSIBILITY

Act as a Principal Product Designer, Senior UI/UX Engineer, Frontend Architect, and Design Systems Engineer.

You are responsible for transforming the existing CloudForge frontend into a complete, polished, production-grade developer cloud platform.

Your work must demonstrate expertise in:

- SaaS product design.
- Developer tools and cloud infrastructure interfaces.
- Enterprise-grade design systems.
- React application architecture.
- Laravel and Inertia.js integration.
- Data-heavy interfaces.
- Responsive design.
- Accessibility.
- Real-time application interfaces.
- Complex operational workflows.

Think like a team designing a commercial cloud platform, not someone building an administrative dashboard.

You must inspect the existing repository, understand its implementation, preserve working functionality, and improve it systematically.

Do not create a disconnected frontend prototype.

---

# 2. PRODUCT VISION

CloudForge is a self-hosted developer cloud platform that allows developers, agencies, and organizations to deploy and manage applications using their own VPS infrastructure.

The product experience should be comparable in simplicity and polish to Laravel Cloud, while offering CloudForge-specific capabilities.

Users should be able to:

1. Connect their GitHub account.
2. Import a repository.
3. Create an application.
4. Automatically detect the application framework.
5. Configure the runtime.
6. Deploy applications.
7. Provision databases.
8. Attach databases to applications.
9. Configure domains and HTTPS.
10. Monitor deployments and application health.
11. Manage workers and scheduled processes.
12. Monitor infrastructure resources.
13. Manage multiple VPS servers.
14. Perform backups and restores.
15. Manage teams, permissions, and organizations.

The interface must make these operations straightforward without requiring users to understand Docker, Linux, systemd, or complex networking concepts.

Advanced infrastructure controls should remain accessible through progressive disclosure.

## Product positioning

CloudForge is not:

- A generic server management panel.
- A Docker container dashboard.
- A generic Laravel admin panel.
- A Coolify clone.
- A static dashboard with simulated metrics.

CloudForge is an application-centric developer cloud with self-hosted infrastructure capabilities.

---

# 3. APPROVED DESIGN DIRECTION

The approved design direction combines two influences.

### A. Laravel Cloud-inspired experience

Use the following design principles:

- Minimal and elegant interfaces.
- Spacious layouts.
- Clean typography.
- Simple navigation.
- Clear information hierarchy.
- Application-centric workflows.
- Consistent forms and dialogs.
- Restrained use of colors.
- Minimal visual noise.
- Progressive disclosure.
- Excellent empty states.
- Simple deployment experiences.

### B. Original CloudForge identity

CloudForge must establish its own visual identity through:

- An original color system.
- Distinctive brand treatment.
- Original icons and illustrations.
- Infrastructure-specific visualizations.
- Application and resource cards.
- Operational health indicators.
- Deployment timelines.
- Server and database visualizations.
- A consistent component language.

Do not copy Laravel Cloud's logo, branding, proprietary illustrations, or exact screen compositions.

The goal is to achieve a comparable level of usability and visual quality, not to reproduce its interface.

## Design personality

CloudForge should feel:

- Professional.
- Reliable.
- Modern.
- Minimal.
- Technical but approachable.
- Premium.
- Calm.
- Consistent.

Avoid:

- Excessive gradients.
- Excessive glassmorphism.
- Heavy shadows.
- Unnecessary animations.
- Oversized dashboard cards.
- Too many colors.
- Excessive borders.
- Dense information everywhere.
- Generic admin-template styling.
- Unnecessary charts and decorative statistics.

---

# 4. VISUAL DESIGN SYSTEM

Establish a centralized design system before implementing individual screens.

The following values are initial design directions. Inspect the existing repository, validate accessibility, and refine them where necessary.

## 4.1 Color palette

Suggested primary palette:

- Forge Navy: #101828
- Forge Blue: #2563EB
- Canvas: #F8FAFC
- Surface: #FFFFFF
- Operational Teal: #0D9488
- Slate: #64748B
- Border: #E2E8F0

Semantic colors:

- Success: Green.
- Warning: Amber.
- Error: Red.
- Information: Blue.
- Neutral: Slate.

Define semantic tokens rather than scattering raw color values across components.

Example token categories:

- background
- foreground
- card
- card-foreground
- primary
- primary-foreground
- secondary
- muted
- muted-foreground
- accent
- border
- input
- ring
- success
- warning
- destructive
- info

All colors must maintain appropriate contrast.

## 4.2 Typography

Use Inter or Geist, depending on existing project conventions and availability.

Establish a consistent typography scale:

- Display.
- Page title.
- Section title.
- Card title.
- Body.
- Secondary text.
- Labels.
- Metadata.
- Code and technical values.

Technical information such as deployment IDs, commit hashes, IP addresses, and resource measurements should use suitable monospace typography.

## 4.3 Spacing

Establish a consistent spacing scale.

Use predictable spacing between:

- Navigation items.
- Page sections.
- Cards.
- Forms.
- Labels.
- Input controls.
- Tables.
- Dialogs.
- Action groups.

Avoid arbitrary one-off spacing values.

## 4.4 Components

Build reusable components for:

- Buttons.
- Icon buttons.
- Inputs.
- Textareas.
- Selects.
- Comboboxes.
- Checkboxes.
- Radio groups.
- Switches.
- Date controls.
- Tabs.
- Badges.
- Tooltips.
- Popovers.
- Dropdown menus.
- Dialogs.
- Confirmation dialogs.
- Drawers.
- Cards.
- Tables.
- Pagination.
- Breadcrumbs.
- Navigation items.
- Avatars.
- Skeleton loaders.
- Empty states.
- Error states.
- Status indicators.
- Progress indicators.
- Copy-to-clipboard controls.
- Code blocks.
- Log viewers.
- Resource usage indicators.
- Charts.

Prefer the existing component library if it is already implemented correctly.

If shadcn/ui or another suitable component foundation exists, reuse and extend it instead of creating competing component systems.

All shared components must have consistent visual behavior and accessible interaction states.

## 4.5 Iconography

Use a consistent icon library, preferably Lucide.

Rules:

- Use icons consistently.
- Avoid mixing unrelated icon styles.
- Do not use emojis as product icons.
- Do not use icons without clear meaning.
- Provide tooltips for icon-only actions.
- Use consistent icon sizes and stroke weights.

## 4.6 Motion and interaction

Use subtle transitions for:

- Navigation.
- Dialogs.
- Dropdowns.
- Tabs.
- Loading indicators.
- Expandable sections.
- Deployment progress.

Respect reduced-motion preferences.

Do not animate everything.

---

# 5. APPLICATION SHELL AND GLOBAL NAVIGATION

Build a reusable application shell shared by all authenticated product screens.

## 5.1 Main layout

The main application layout should contain:

- Left sidebar.
- Organization switcher.
- Workspace navigation.
- Infrastructure navigation.
- Management navigation.
- Main content area.
- Global header.
- Breadcrumbs.
- Notifications.
- User account menu.

The navigation should be compact, clear, and easy to scan.

Support a collapsible sidebar where appropriate.

## 5.2 Organization switcher

Users must be able to:

- View their current organization.
- Switch organizations.
- View their organization role.
- Access organization settings.
- Create an organization if supported by the backend.

Do not display organization switching options that the backend cannot support.

## 5.3 Main navigation

### Workspace

- Overview.
- Applications.
- Databases.
- Projects.
- Activity.

### Infrastructure

- Servers.
- Networking.
- Domains.
- Backups.

### Management

- Team.
- API Tokens.
- Audit Logs.
- Organization Settings.

Use route-aware active states.

Hide or disable navigation entries according to permissions and actual feature availability.

## 5.4 Global header

Include:

- Breadcrumbs.
- Page-level actions.
- Search where supported.
- Notifications.
- User profile menu.

Do not introduce decorative controls without working functionality.

---

# 6. OVERVIEW DASHBOARD

Create a professional organization overview.

The dashboard must show actual backend data.

## Required sections

### Resource summary

Display:

- Applications.
- Active deployments.
- Databases.
- Connected servers.
- Failed operations.
- Resource health.

### Recent activity

Show:

- Recent deployments.
- Failed builds.
- Server changes.
- Database provisioning.
- Team activity.

### Infrastructure health

Show:

- Server availability.
- CPU utilization.
- Memory utilization.
- Disk usage.
- Network activity.

### Attention required

Display actionable issues such as:

- Unreachable servers.
- Failed deployments.
- Unhealthy applications.
- Failed backups.
- Expiring or invalid certificates.
- Infrastructure warnings.

### Quick actions

Where supported:

- Create application.
- Import repository.
- Create database.
- Add server.

### Dashboard behavior

- Do not hardcode statistics.
- Do not use fake charts.
- Display useful empty states for new organizations.
- Use skeletons while loading.
- Handle API failures.
- Provide refresh behavior.
- Use real backend data.

---

# 7. APPLICATION MANAGEMENT

Applications are the central resource of CloudForge.

Create a dedicated Applications section.

## 7.1 Applications listing

Display:

- Application name.
- Framework.
- Project.
- Environment.
- Deployment status.
- Domain.
- Target server.
- Last deployment.
- Health status.

Provide:

- Search.
- Filters.
- Sorting.
- Pagination.
- Create application action.
- Useful empty state.

Use a clean table or card layout depending on the amount of information and viewport size.

## 7.2 Application creation wizard

Create a guided multi-step application creation experience.

### Step 1 — Source repository

Allow users to:

- Connect GitHub.
- Select an existing connected account.
- Select a repository.
- Select a branch.
- Review repository information.

Handle missing GitHub permissions and connection errors.

### Step 2 — Framework detection

Automatically detect supported frameworks where the backend provides detection results.

Initial framework targets:

- Laravel.
- Node.js.
- Next.js.
- Nuxt.
- Express.
- NestJS.
- Static websites.
- Docker-based applications.

Show:

- Detected framework.
- Runtime version.
- Build configuration.
- Start command.
- Port configuration.

Allow manual overrides when supported.

### Step 3 — Project and environment

Select:

- Project.
- Environment.
- Application name.

### Step 4 — Infrastructure

Select:

- Target server.
- Deployment resources.
- Runtime configuration.

Allow automatic server placement only when the orchestration backend supports it.

### Step 5 — Review and deploy

Display a clear configuration summary.

Allow users to go back and edit earlier steps.

Do not submit incomplete or invalid configuration.

## 7.3 Application detail workspace

Every application must have a dedicated detail page.

Application navigation:

1. Overview.
2. Deployments.
3. Source.
4. Runtime.
5. Environment Variables.
6. Domains.
7. Processes.
8. Logs.
9. Metrics.
10. Settings.

Maintain the application's identity and context across these sections.

### Application overview

Display:

- Application name.
- Framework.
- Deployment status.
- Current domain.
- Latest deployment.
- Target server.
- Runtime health.
- CPU and memory usage.
- Recent activity.
- Quick actions.

### Source

Display:

- Connected GitHub account.
- Repository.
- Branch.
- Latest commit.
- Commit author.
- Commit message.
- Automatic deployment configuration.

Allow source configuration changes only through supported backend actions.

### Runtime

Display:

- Runtime type.
- Runtime version.
- Build command.
- Start command.
- Port.
- Health check.
- Resource limits.
- Runtime-specific configuration.

### Environment variables

Implement a professional environment-variable manager.

Support:

- Key.
- Value.
- Secret masking.
- Reveal and hide.
- Copy.
- Add.
- Edit.
- Delete.
- Environment-specific configuration.
- Confirmation for destructive changes.

Never expose secret values unnecessarily.

### Domains

Display:

- Primary domain.
- Additional domains.
- DNS verification status.
- TLS certificate status.
- HTTPS availability.

Allow supported operations:

- Add domain.
- Remove domain.
- Set primary domain.
- Verify DNS.
- View configuration instructions.

### Processes

Support application process management according to backend capabilities.

Display applicable processes:

- Web.
- PHP-FPM.
- Octane.
- Queue workers.
- Horizon.
- Scheduler.
- Reverb.

Do not display unsupported process types as if they are functional.

### Logs

Provide:

- Application logs.
- Container logs.
- Build logs.
- Deployment logs.
- Time filters.
- Search.
- Level filters.
- Auto-scroll.
- Pause and resume.
- Copy.
- Download where supported.

Logs must use actual backend data or a real log-streaming connection.

### Metrics

Display actual:

- CPU.
- Memory.
- Disk.
- Network.
- Container resource usage.

Charts should be readable and not unnecessarily decorative.

### Settings

Include supported operations such as:

- Rename application.
- Change deployment configuration.
- Configure health checks.
- Configure restart behavior.
- Configure resource limits.
- Delete application.

All destructive actions require confirmation.

---

# 8. DEPLOYMENT EXPERIENCE

Deployments must be treated as a first-class product experience.

## 8.1 Deployment history

Display:

- Deployment ID.
- Commit.
- Branch.
- Author.
- Trigger.
- Start time.
- Duration.
- Status.

Statuses should include actual lifecycle states, for example:

- Queued.
- Preparing.
- Building.
- Deploying.
- Health checking.
- Active.
- Failed.
- Cancelled.
- Rolled back.

Use backend lifecycle states rather than inventing client-side statuses.

## 8.2 Deployment detail

Display:

- Commit information.
- Build configuration.
- Deployment target.
- Lifecycle timeline.
- Build logs.
- Deployment logs.
- Health-check results.
- Error diagnostics.
- Final deployment status.

## 8.3 Deployment actions

Implement supported actions:

- Deploy.
- Redeploy.
- Cancel.
- Roll back.
- View logs.

Use confirmation where appropriate.

## 8.4 Live deployment experience

Provide real-time updates through the existing event infrastructure or an implemented backend streaming mechanism.

Do not simulate deployment progress.

If the connection is lost:

- Indicate disconnection.
- Preserve visible logs.
- Attempt supported reconnection.
- Recover current deployment state from the backend.

---

# 9. DATABASE MANAGEMENT

Databases must be first-class CloudForge resources.

## 9.1 Database listing

Display:

- Name.
- Engine.
- Version.
- Status.
- Server.
- Storage.
- Attached applications.
- Backup status.

Supported initial engines:

- PostgreSQL.
- MySQL.
- Redis.

## 9.2 Database provisioning wizard

Provide a guided workflow.

### Step 1 — Engine

Choose PostgreSQL, MySQL, or Redis.

### Step 2 — Configuration

Configure supported options:

- Database name.
- Version.
- Resource allocation.
- Storage.
- Credentials.
- Network access.

### Step 3 — Infrastructure

Choose:

- Target server.
- Shared or dedicated deployment where supported.

### Step 4 — Review

Show a configuration summary.

### Step 5 — Provision

Start the real provisioning operation.

Show:

- Provisioning status.
- Operation logs.
- Errors.
- Completion status.

## 9.3 Database detail workspace

Include:

- Overview.
- Connections.
- Attached applications.
- Backups.
- Metrics.
- Logs.
- Settings.

## 9.4 Attach database to application

Implement a simple attachment workflow.

The user should:

1. Select an application.
2. Select the target environment.
3. Review the database connection.
4. Confirm attachment.
5. Allow CloudForge to configure the application's environment variables through the backend.

Do not expose database credentials in URLs or browser logs.

Show successful attachment only after the backend confirms it.

## 9.5 Database security

- Mask credentials by default.
- Support credential rotation where implemented.
- Do not expose public database ports by default.
- Show private connection information.
- Make network exposure explicit.
- Clearly communicate destructive database operations.

---

# 10. SERVER MANAGEMENT

Server management is important, but it must not dominate the entire product experience.

## 10.1 Server listing

Display:

- Server name.
- IP or hostname where authorized.
- Region if available.
- Operating system.
- Agent status.
- Docker status.
- CPU.
- Memory.
- Disk.
- Assigned resources.
- Last heartbeat.

## 10.2 Server onboarding

The VPS hosting the initial CloudForge installation must appear automatically if the backend bootstrap process registers it successfully.

Do not force users to manually enroll the same server.

Additional servers may be enrolled using the existing secure enrollment workflow.

Provide:

- Server name.
- Enrollment instructions.
- Installation command.
- Enrollment status.
- Connection verification.
- Completion state.

Never display private enrollment tokens after their permitted retrieval window.

## 10.3 Server detail

Include:

- Overview.
- Resources.
- Applications.
- Databases.
- Containers.
- Metrics.
- Agent status.
- Activity.
- Settings.

Display actual server health and inventory.

## 10.4 Server operations

Implement only backend-supported operations.

Examples:

- Maintenance mode.
- Resource inspection.
- Server rename.
- Agent status.
- Server removal.
- Draining workloads.

Require confirmation for operations that affect workloads.

---

# 11. NETWORKING AND DOMAINS

## Networking

Provide a clear overview of:

- Private networks.
- Server connectivity.
- Application connectivity.
- Database connectivity.
- Network policies.

Where supported, visualize relationships between resources.

Do not display imaginary topology.

## Domains

Provide:

- Domain listing.
- Application association.
- DNS verification.
- TLS certificate status.
- HTTPS status.
- Renewal status.
- Domain configuration instructions.

Explain DNS requirements in simple language.

Keep advanced DNS and routing details available in expandable sections.

---

# 12. BACKUPS

Create a dedicated backup experience.

Display:

- Resource.
- Backup type.
- Creation time.
- Size.
- Status.
- Retention.
- Last successful backup.

Provide supported actions:

- Create backup.
- View backup.
- Restore.
- Delete.

Restore operations must require explicit confirmation.

Show actual backup operation progress and failure details.

---

# 13. PROJECTS AND ENVIRONMENTS

Projects group related applications and resources.

Provide:

- Project listing.
- Project creation.
- Project detail.
- Applications within project.
- Associated databases.
- Environment management.

Support environments such as:

- Production.
- Staging.
- Development.

Environment configuration must remain separate where required.

Do not assume all projects use the same environment names or deployment rules.

---

# 14. TEAM AND ORGANIZATION MANAGEMENT

Create professional management screens.

## Team

Display:

- Member.
- Email.
- Role.
- Invitation status.
- Date joined.

Support the existing roles:

- Owner.
- Admin.
- Developer.
- Operator.
- Viewer.

Provide supported operations:

- Invite.
- Resend invitation.
- Change role.
- Remove member.

Respect authorization policies.

## API Tokens

Display:

- Token name.
- Abilities.
- Creation date.
- Last used date.
- Expiration.
- Status.

Allow supported actions:

- Create.
- Reveal once if supported.
- Copy.
- Revoke.

Never reveal hashed or inaccessible token values.

## Audit Logs

Display:

- Actor.
- Action.
- Resource.
- Timestamp.
- Result.
- Relevant metadata.

Provide filters and pagination.

## Organization Settings

Include:

- Organization name.
- Members.
- Permissions.
- API configuration.
- Security settings.
- Organization deletion where supported.

---

# 15. GLOBAL INTERACTION REQUIREMENTS

Every interface must have complete interaction behavior.

Implement:

- Search.
- Sorting.
- Filtering.
- Pagination.
- Tabs.
- Dropdowns.
- Context menus.
- Dialogs.
- Drawers.
- Confirmation prompts.
- Copy actions.
- Form validation.
- Toast notifications.
- Loading states.
- Empty states.
- Error states.
- Permission-aware controls.

Do not implement controls that only look interactive.

Every button must perform its advertised action.

Every form must submit to a real backend endpoint or an explicitly documented implementation task.

Avoid unnecessary page reloads when Inertia or existing client-side behavior supports the interaction.

Preserve state where appropriate.

---

# 16. REAL-TIME EXPERIENCE

CloudForge has operational events that require timely updates.

Where the backend supports it, use the existing real-time infrastructure.

Examples:

- Deployment progress.
- Build logs.
- Server health.
- Agent connectivity.
- Database provisioning.
- Backup progress.
- Application health.

Requirements:

- Show connection state.
- Handle disconnections.
- Recover state after reconnecting.
- Avoid duplicate events.
- Prevent uncontrolled rendering from high-frequency metrics.
- Provide sensible update frequencies.
- Use backend state as the source of truth.

Do not use timers or random values to simulate operational activity.

---

# 17. RESPONSIVE DESIGN

Design desktop-first, but ensure the interface remains usable on smaller screens.

## Desktop

- Full sidebar.
- Multi-column layouts where appropriate.
- Complete tables.
- Side-by-side resource details.
- Comfortable content width.

## Tablet

- Collapsible navigation.
- Responsive cards.
- Simplified tables.
- Appropriate form widths.

## Mobile

- Compact navigation.
- Mobile-friendly actions.
- Stacked content.
- Horizontally scrollable data tables only where necessary.
- Readable log viewers.
- Touch-friendly controls.
- No horizontal page overflow.

Do not simply shrink the desktop layout.

---

# 18. ACCESSIBILITY

Follow practical accessibility standards.

Implement:

- Semantic HTML.
- Keyboard navigation.
- Visible focus indicators.
- Appropriate ARIA attributes.
- Accessible labels.
- Accessible dialogs.
- Screen-reader-friendly status messages.
- Sufficient contrast.
- Reduced-motion support.

Do not use color as the only indicator of operational status.

---

# 19. FRONTEND ARCHITECTURE

Existing stack:

- Laravel 13.
- PHP 8.4.
- Inertia.js v3.
- React 19.
- Tailwind CSS.

Preserve the existing architecture and conventions.

Before introducing dependencies, inspect what is already installed.

## Architectural requirements

Separate:

- Application layouts.
- Navigation components.
- Shared UI components.
- Resource-specific components.
- Pages.
- Forms.
- API integration.
- Reusable hooks.
- Types and schemas.
- Utilities.

Suggested organizational structure, adapted to the actual repository:

resources/js/
components/
ui/
layout/
navigation/
applications/
deployments/
databases/
servers/
networking/
shared/
layouts/
pages/
dashboard/
applications/
deployments/
databases/
servers/
networking/
domains/
backups/
projects/
team/
settings/
hooks/
lib/
types/

Do not blindly impose this structure if the repository already has a good architecture.

## Frontend quality

- Use TypeScript where the existing project supports it.
- Avoid unnecessary dependencies.
- Avoid duplicated components.
- Avoid giant page components.
- Avoid excessive prop drilling.
- Keep state ownership clear.
- Keep backend contracts typed where practical.
- Use reusable validation patterns.
- Handle API errors consistently.

---

# 20. BACKEND INTEGRATION REQUIREMENTS

The backend is the source of truth.

Before implementing each screen:

1. Inspect its existing Laravel routes.
2. Inspect controllers and actions.
3. Inspect Inertia page props.
4. Inspect models and relationships.
5. Inspect authorization policies.
6. Inspect API resources and response structures.
7. Identify available operations.
8. Identify missing backend functionality.

Use the existing backend contracts.

Do not fabricate endpoint URLs, fields, response shapes, or capabilities.

If a feature requires backend work:

- Document the missing backend capability.
- Specify the required contract.
- Implement the frontend only when a real contract exists.
- Do not silently substitute static data.

Ensure the UI reflects actual operation state.

---

# 21. UI STATES AND ERROR HANDLING

Every resource screen must account for the following states where applicable:

### Loading

Use skeletons and clear progress indicators.

### Empty

Explain what the resource is and provide a relevant action.

### Error

Show a useful message and recovery action.

### Success

Provide confirmation after the backend reports success.

### Pending

Display actual pending operation status.

### Warning

Explain the condition and its potential impact.

### Offline

Show stale or unavailable data clearly.

### Permission denied

Do not expose unauthorized actions or information.

### Destructive operations

Explain consequences and require confirmation.

Never make an operation appear successful before the backend confirms it.

---

# 22. PERFORMANCE

The interface must remain responsive with large organizations and infrastructure inventories.

Requirements:

- Paginate large datasets.
- Avoid unnecessary API requests.
- Avoid unnecessary React re-renders.
- Lazy-load heavy components where appropriate.
- Optimize log rendering.
- Avoid loading all metrics history at once.
- Use efficient table rendering.
- Avoid excessive real-time subscriptions.
- Clean up subscriptions when components unmount.
- Use appropriate loading and caching strategies.

---

# 23. TESTING AND QUALITY ASSURANCE

Create and maintain frontend tests using the repository's existing testing tools.

Test:

- Navigation.
- Authentication-protected pages.
- Application creation.
- Repository selection.
- Deployment interactions.
- Database provisioning.
- Database attachment.
- Environment-variable management.
- Server management.
- Permission restrictions.
- Form validation.
- Error states.
- Responsive layouts.
- Real-time connection failure handling.

Where practical, use browser-based end-to-end tests for critical workflows.

Do not consider a page complete because it renders without crashing.

A page is complete when its interactions, backend integration, permissions, loading states, errors, and responsive behavior have been verified.

---

# 24. IMPLEMENTATION STRATEGY

Do not redesign the entire application in one uncontrolled operation.

Work in the following sequence.

## Phase 0 — Repository audit

Inspect:

- Existing layouts.
- Existing pages.
- Existing React components.
- Tailwind configuration.
- Component libraries.
- Route definitions.
- Inertia integration.
- Existing frontend tests.
- Backend contracts.

Create:

docs/ui-ux/REPOSITORY_AUDIT.md

Include:

- Existing implementation.
- Reusable components.
- UI inconsistencies.
- Missing screens.
- Backend integration gaps.
- Proposed changes.
- Risks.

Do not delete working features.

## Phase 1 — Design foundation

Implement:

- Design tokens.
- Typography.
- Color system.
- Spacing system.
- Shared component foundation.
- Buttons.
- Inputs.
- Dialogs.
- Dropdowns.
- Cards.
- Tables.
- Badges.
- Skeletons.
- Empty states.
- Error states.

Create:

docs/ui-ux/DESIGN_SYSTEM.md

## Phase 2 — Application shell

Implement:

- Authenticated layout.
- Sidebar.
- Organization switcher.
- Header.
- Breadcrumbs.
- Navigation.
- Account menu.
- Responsive behavior.

Verify that all existing authenticated pages work inside the new shell.

## Phase 3 — Core workspace

Implement:

- Overview dashboard.
- Applications listing.
- Projects.
- Activity.

Use real data and existing backend contracts.

## Phase 4 — Application experience

Implement:

- Application creation wizard.
- Application overview.
- Source.
- Runtime.
- Environment variables.
- Deployments.
- Logs.
- Metrics.
- Domains.
- Processes.
- Settings.

This phase is a priority.

## Phase 5 — Database experience

Implement:

- Database listing.
- Provisioning wizard.
- Database details.
- Connection management.
- Application attachment.
- Backups.
- Metrics.
- Logs.

## Phase 6 — Infrastructure experience

Implement:

- Server listing.
- Server onboarding.
- Server details.
- Resource visualization.
- Networking.
- Domains.
- Backups.

## Phase 7 — Organization management

Implement:

- Team.
- Invitations.
- Permissions.
- API tokens.
- Audit logs.
- Organization settings.

## Phase 8 — Integration and QA

Verify:

- All routes.
- All navigation.
- All actions.
- All forms.
- All permissions.
- Backend integration.
- Responsive behavior.
- Loading and error states.
- Accessibility.
- Performance.

Fix issues before declaring completion.

---

# 25. STRICT IMPLEMENTATION RULES

These rules are mandatory.

1. Inspect before editing.
2. Preserve existing backend functionality.
3. Preserve working authentication and authorization.
4. Do not replace the existing stack.
5. Do not introduce unnecessary dependencies.
6. Do not create static dashboard metrics.
7. Do not create fake deployments.
8. Do not simulate server health.
9. Do not simulate database provisioning.
10. Do not create decorative buttons.
11. Do not create dead links.
12. Do not expose secrets.
13. Do not bypass authorization.
14. Do not invent backend endpoints.
15. Do not hide errors from users.
16. Do not mark pending operations as successful.
17. Do not create multiple competing design systems.
18. Do not leave unfinished placeholder screens while claiming completion.
19. Do not remove existing functionality without a documented reason.
20. Do not stop after creating the design system or implementation plan.

When backend functionality is missing, document it precisely instead of pretending it exists.

Implement complete vertical slices whenever possible.

---

# 26. DEFINITION OF DONE

The UI/UX implementation is complete only when all of the following are satisfied.

### Visual quality

- The interface consistently follows the approved A+B direction.
- The CloudForge identity is recognizable.
- Navigation is consistent.
- Components are reusable.
- Spacing and typography are consistent.
- No generic admin-template appearance remains.

### Functional quality

- All implemented actions work.
- All forms validate.
- All resource pages use real data.
- Application workflows connect to the backend.
- Deployment state is real.
- Database workflows connect to the backend.
- Server state is real.
- Permissions are respected.
- Errors are handled correctly.

### User experience

A new user can understand the platform without reading technical documentation for every operation.

The user can navigate from the organization dashboard to an application, deploy it, configure its environment, attach a database, configure its domain, and inspect its logs through a coherent interface.

### Engineering quality

- Existing tests continue to pass.
- New critical workflows have tests.
- No major console errors.
- No broken routes.
- No obvious responsive defects.
- No fake data in production interfaces.
- No unresolved critical accessibility issues.

---

# 27. REQUIRED FINAL REPORT

When implementation is complete, provide:

1. Repository audit summary.
2. Design system implementation summary.
3. Screens implemented.
4. Shared components created or refactored.
5. Existing screens improved.
6. Backend integrations completed.
7. Backend capabilities still missing.
8. Tests executed and their results.
9. Responsive and accessibility verification.
10. Known limitations.
11. Remaining implementation tasks.

Clearly distinguish between:

- Fully implemented.
- Partially implemented.
- UI completed but backend unavailable.
- Not implemented.

Do not claim full completion if important workflows remain non-functional.

---

# 28. IMMEDIATE EXECUTION INSTRUCTION

Start by inspecting the actual CloudForge repository.

Create the repository audit and implementation plan.

Then begin implementation immediately.

Do not stop after producing documentation.

Do not ask me to approve every individual component or screen.

Make reasonable product-design decisions consistent with the approved A+B direction.

Preserve existing working functionality.

Prioritize the shared design foundation, application shell, and application/deployment experience.

The final goal is a complete, production-grade CloudForge developer cloud interface that combines Laravel Cloud's simplicity with CloudForge's own infrastructure capabilities and brand identity.

Build the actual product interface, not a visual demonstration.
