# CLOUDFORGE — PROJECTS MODULE

# PROFESSIONAL UI/UX DESIGN & IMPLEMENTATION PROMPT

## 1. ROLE

Act as a Principal SaaS Product Designer, Senior React Engineer, Laravel/Inertia Architect, and UI/UX Specialist.

Your task is to design and implement the complete Projects module for CloudForge.

You are responsible for two primary experiences:

1. Projects Listing Page.
2. Project Details Page.

Both pages must meet the quality standards of a professional commercial cloud platform.

Use Laravel Cloud as the primary UX reference for simplicity, hierarchy, spacing, and developer experience. Combine this with CloudForge's own visual identity and infrastructure-management capabilities.

Do not create a generic CRUD interface or an administrative dashboard.

The result must look like a native part of a polished developer cloud platform.

---

# 2. PRODUCT CONTEXT

CloudForge is an application-centric, self-hosted developer cloud.

A Project is a logical workspace that groups related applications, databases, and deployment environments.

For example:

Project: E-commerce Platform

Resources:

- storefront-web
- storefront-api
- background-worker
- PostgreSQL database
- Redis database

Environments:

- Production
- Staging
- Development

A project should provide developers with a unified view of their application's ecosystem.

Important distinction:

- Organization: The top-level account and ownership boundary.
- Project: A workspace grouping related resources.
- Environment: An isolated deployment configuration within a project.
- Application: A deployable software resource.
- Database: A provisioned infrastructure resource.

Do not confuse projects with applications or environments.

Inspect the existing domain model and adapt these concepts to the actual implementation.

---

# 3. DESIGN DIRECTION

Follow the previously approved CloudForge A+B visual direction.

## A — Laravel Cloud influence

- Minimalist interface.
- Clean layouts.
- Spacious content.
- Elegant typography.
- Clear hierarchy.
- Simple resource management.
- Professional tables and cards.
- Contextual actions.
- Consistent forms.
- Uncomplicated navigation.

## B — CloudForge identity

- Original CloudForge branding.
- Distinctive color system.
- Infrastructure-aware project summaries.
- Application and database resource visualization.
- Deployment health indicators.
- Environment-aware navigation.
- Professional operational information.

The interface should feel premium, simple, and technically capable.

Do not copy Laravel Cloud's exact layouts, branding, illustrations, or proprietary assets.

## Visual principles

- Light theme as the default.
- White and subtle neutral surfaces.
- Forge Navy typography.
- Forge Blue primary actions.
- Operational Teal for positive infrastructure states.
- Restrained shadows.
- Subtle borders.
- Consistent rounded corners.
- Consistent iconography using Lucide.
- Responsive layouts.

Reuse the existing CloudForge design system. Do not introduce a separate design system for Projects.

---

# 4. PROJECTS LISTING PAGE

Route should follow the existing application routing conventions. Suggested route:

/projects

The Projects page is the central location where users discover, organize, and manage their projects.

It must be visually clean, informative, and action-oriented.

## 4.1 Page header

Create a professional page header.

Title:

Projects

Description:

Organize your applications, databases, and environments in one workspace.

Primary action:

Create Project

Optional secondary actions should only be included if supported by the product.

The header must have a clear visual hierarchy without excessive decoration.

## 4.2 Project overview summary

Show a compact summary of project resources.

Possible metrics:

- Total projects.
- Applications.
- Databases.
- Active environments.

These values must come from real backend data.

Do not turn the page into a dashboard filled with unnecessary statistics.

Keep the summary visually restrained.

If the existing backend does not expose aggregate data, implement only what can be calculated reliably from available data or request an appropriate backend contract.

## 4.3 Project listing

Create a polished project listing.

The default presentation should use professional project cards or a clean table, based on the existing CloudForge design language and the amount of available information.

Each project item should display:

- Project name.
- Project description, if available.
- Project icon or initial-based avatar.
- Number of applications.
- Number of databases.
- Number of environments.
- Overall project health, only if reliably calculated.
- Last activity.
- Creation date, where useful.

Example project data for design reference only:

Project: E-commerce Platform
Description: Main commerce platform and supporting services.
Applications: 3
Databases: 2
Environments: 3
Last activity: Recent deployment.

Project: Customer Portal
Description: Customer-facing application and API.
Applications: 2
Databases: 1
Environments: 2

These are illustrative examples, not production data.

Never insert these values as hardcoded production records.

## 4.4 Project card design

Cards must feel like professional cloud-resource cards.

Include:

- Project identity.
- Project name.
- Description.
- Compact resource summary.
- Relevant status indicators.
- Last activity.
- Context menu.

Use subtle hover states.

The entire card should navigate to the project details page where appropriate.

Avoid unnecessary gradients and decorative graphics.

Do not overcrowd cards.

## 4.5 Search and filtering

Provide:

- Search by project name.
- Sorting by name.
- Sorting by recent activity.
- Sorting by creation date where supported.
- Pagination or load-more behavior according to the backend.
- Filters only where the backend supports meaningful filtering.

Search should be responsive and should not cause excessive requests.

Preserve search and filter state where appropriate.

## 4.6 Project context menu

Provide contextual actions according to permissions and backend support:

- Open project.
- Edit project.
- Manage environments.
- Delete project.

Destructive actions must require explicit confirmation.

Do not show unsupported actions.

## 4.7 Create Project dialog or page

Implement a polished creation workflow.

Required fields:

- Project name.
- Project description, optional if supported.

Optional fields may include:

- Project icon or color, only if supported by the model.
- Initial environment, only if supported by the backend.

Requirements:

- Clear labels.
- Helpful descriptions.
- Validation.
- Appropriate maximum lengths.
- Loading state.
- Backend error handling.
- Success notification.
- Navigation or list refresh after successful creation.

Do not add unnecessary configuration to project creation.

The goal is to make creating a project fast.

## 4.8 Edit Project

Support editing the fields exposed by the backend.

Use a consistent form experience.

Handle:

- Validation.
- Permission restrictions.
- Save progress.
- API errors.
- Success feedback.

## 4.9 Empty state

When an organization has no projects, display a professional empty state.

Suggested copy:

Title:
Your workspace starts with a project.

Description:
Create a project to organize your applications, databases, and deployment environments.

Primary action:
Create your first project.

Use an original CloudForge illustration or a subtle icon-based visual.

Do not show fabricated project records.

## 4.10 Loading and error states

Implement:

- Skeleton cards or table rows.
- Loading indicators.
- API error state.
- Retry action.
- Permission-denied state.
- Empty state.

---

# 5. PROJECT DETAILS PAGE

Suggested route:

/projects/{project}

Use the actual route structure and route-model binding conventions in the repository.

The Project Details page is the most important part of this module.

It should feel like entering a dedicated workspace for a software system.

The user must immediately understand:

- Which project they are viewing.
- What resources belong to it.
- Which environments exist.
- Whether its applications are healthy.
- What happened recently.
- What actions they can perform.

Avoid treating this page as a simple project information form.

---

# 6. PROJECT DETAILS — PAGE STRUCTURE

## 6.1 Project header

At the top of the page, display:

- Breadcrumb: Projects / Project Name.
- Project icon or identity.
- Project name.
- Project description.
- Project creation information where useful.
- Project actions.

Primary or contextual actions:

- Create application.
- Create database.
- Project settings.

Only show actions that the current user is authorized to execute.

The header should be compact and elegant.

## 6.2 Project navigation

Create a dedicated project-level navigation.

Recommended sections:

1. Overview.
2. Applications.
3. Databases.
4. Environments.
5. Activity.
6. Settings.

The active section must be visually clear.

Keep the project identity visible while navigating between sections.

Use nested routes if the existing application architecture supports them.

Avoid duplicating organization-level navigation.

---

# 7. PROJECT OVERVIEW

The Overview tab should provide a complete picture of the project.

## 7.1 Resource summary

Show:

- Total applications.
- Total databases.
- Total environments.
- Healthy resources.
- Resources requiring attention.

Use compact cards with clear labels.

Only calculate health from real resource states.

Do not assume that all project resources have health-check capabilities.

## 7.2 Environments overview

Display the project's environments.

Examples:

- Production.
- Staging.
- Development.

For each environment, display available information:

- Environment name.
- Environment type.
- Number of applications.
- Number of databases.
- Deployment activity.
- Environment status, if supported.

Provide a way to open an environment's details.

The environment display must reflect the actual database schema and environment model.

Do not create fictional environment types.

## 7.3 Applications overview

Display applications associated with the project.

Each application should show:

- Name.
- Framework.
- Deployment status.
- Current domain.
- Environment.
- Target server, where authorized.
- Latest deployment.

Provide an action to open the application.

Include a clear Create Application action when authorized.

## 7.4 Databases overview

Display databases associated with the project.

Each database should show:

- Name.
- Engine.
- Version.
- Status.
- Environment.
- Attached application count, where available.

Provide a Create Database action when supported.

## 7.5 Recent activity

Display recent project-level activity:

- Application deployments.
- Failed deployments.
- Database provisioning.
- Environment creation.
- Resource configuration changes.
- Relevant team actions.

Use real activity records.

Include timestamps and relevant actor information where available.

Provide an action to view all project activity.

## 7.6 Resource health and attention

Show actionable issues only when actual backend state indicates a problem.

Examples:

- Failed application deployment.
- Unhealthy application.
- Unreachable target server.
- Failed database provisioning.
- Resource requiring maintenance.

Do not create false alarms based on incomplete data.

---

# 8. PROJECT APPLICATIONS TAB

Suggested nested route:

/projects/{project}/applications

This section should display only applications belonging to the current project.

Provide:

- Application name.
- Framework.
- Environment.
- Deployment status.
- Domain.
- Last deployment.
- Target server, if available.

Support:

- Search.
- Sorting.
- Filtering by environment.
- Filtering by deployment status.
- Pagination.

Provide a Create Application action.

The action must open the existing application creation flow with the current project preselected.

Do not implement a second, independent application creation wizard.

Preserve project context throughout the workflow.

If the existing backend does not support project-scoped filtering, implement the required backend contract instead of filtering incomplete data in the browser.

---

# 9. PROJECT DATABASES TAB

Suggested nested route:

/projects/{project}/databases

Show all databases associated with the current project.

Display:

- Database name.
- Engine.
- Version.
- Environment.
- Provisioning status.
- Target server.
- Application attachments.

Provide:

- Search.
- Filters.
- Sorting.
- Pagination.
- Create Database action.

The Create Database action must open the existing database provisioning workflow with the current project context preselected.

Database details must link to their existing dedicated detail pages.

Never duplicate database provisioning logic.

---

# 10. PROJECT ENVIRONMENTS TAB

Suggested nested route:

/projects/{project}/environments

Environments are important because applications and databases can have different configurations and deployment lifecycles.

Display:

- Environment name.
- Environment type.
- Associated applications.
- Associated databases.
- Latest deployment activity.
- Creation date.
- Environment status where supported.

Provide:

- Create environment.
- Edit environment.
- Open environment.
- Delete environment where allowed.

The creation form should remain simple.

Required fields must match the backend schema.

## Environment detail

If the current architecture supports a dedicated environment detail page, display:

- Environment identity.
- Project association.
- Applications.
- Databases.
- Environment-specific resources.
- Recent deployments.
- Relevant configuration.

Environment-specific secrets and configuration must not be exposed unnecessarily.

Clearly distinguish production from non-production environments using subtle visual treatment.

Do not assume that an environment is production merely because its name contains "production" unless the backend defines that semantic.

---

# 11. PROJECT ACTIVITY TAB

Suggested nested route:

/projects/{project}/activity

Provide a chronological activity feed.

Display:

- Event.
- Resource.
- Actor.
- Timestamp.
- Status.
- Relevant metadata.

Filters:

- Applications.
- Databases.
- Deployments.
- Environments.
- Team activity.

Use actual audit or activity records.

Do not generate fake events.

Provide pagination or cursor-based loading based on the backend.

---

# 12. PROJECT SETTINGS TAB

Suggested nested route:

/projects/{project}/settings

Organize settings into clear sections.

## General

- Project name.
- Project description.
- Project identity, if supported.

## Resources

Show project resource counts and relationships.

## Access

Display relevant permissions if project-level permissions exist.

Do not invent a separate permission system if access is inherited from the organization.

## Danger zone

Provide project deletion where authorized.

Before deletion:

- Explain what will happen.
- Identify dependent resources where possible.
- Clarify whether resources will be deleted or detached.
- Require explicit confirmation.
- Handle backend restrictions.
- Do not silently cascade-delete resources.

The backend must remain responsible for enforcing deletion policies.

---

# 13. PROJECT CONTEXT AND WORKFLOWS

Project context must be preserved throughout CloudForge.

When users create resources from inside a project:

- The project must be preselected.
- The current environment should be preserved where relevant.
- The user should not need to navigate backward to select the project again
