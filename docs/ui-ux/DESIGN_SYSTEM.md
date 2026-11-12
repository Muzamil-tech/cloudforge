# CloudForge design system

This is the shared visual and interaction language for the authenticated control
plane. It extends the existing CloudForge identity rather than replacing it with
a generic admin theme.

## Direction

CloudForge is calm, technical and application-centric. Interfaces use a warm
neutral canvas, fine borders, restrained teal accents, Instrument Sans for prose
and JetBrains Mono for infrastructure facts.

Dark mode is available from the global header; light mode is the default. The
choice is stored in `localStorage` as `cf-theme`.

## Colour tokens

Defined in `resources/css/app.css` and consumed through Tailwind theme colours.

| Token | Light | Dark | Use |
| --- | --- | --- | --- |
| `--cf-bg` | `#f8fafc` | `#0b0f14` | Page canvas |
| `--cf-surface` | `#ffffff` | `#10161d` | Cards and shells |
| `--cf-surface-raised` | `#f1f5f9` | `#141b23` | Inputs, raised blocks |
| `--cf-line` | `#e2e8f0` | `#1f2833` | Default borders |
| `--cf-line-strong` | `#cbd5e1` | `#2c3845` | Input borders |
| `--cf-text` | `#101828` | `#e8edf2` | Primary text |
| `--cf-muted` | `#64748b` | `#8b98a7` | Secondary text |
| `--cf-faint` | `#94a3b8` | `#5f6b79` | Metadata |
| `--cf-accent` | `#2563eb` | `#60a5fa` | Primary actions and links |
| `--cf-accent-foreground` | `#ffffff` | `#06101f` | Text on primary |
| `--cf-danger` | `#dc2626` | `#fb7185` | Destructive/error |
| `--cf-warning` | `#d97706` | `#fbbf24` | Attention |
| `--cf-success` | `#0d9488` | `#34d399` | Healthy/operational |
| `--cf-info` | `#2563eb` | `#60a5fa` | Informational state |

Semantic status colours always pair with a label and a dot/icon; colour alone is
not the only signal.

## Typography

- UI: Instrument Sans, 400/500/600/700.
- Technical values, IDs, hostnames, ports, commit-like strings: JetBrains Mono.
- Page title: `text-2xl font-semibold tracking-tight`.
- Section title: `text-sm font-semibold`.
- Body: `text-sm leading-6`.
- Secondary: `text-xs` with `text-muted`.
- Labels: `.cf-label` (mono, 11px, uppercase, tracked).
- Metadata: mono 10–11px.

## Spacing and shape

- Page gutters: `px-4 sm:px-6 lg:px-8`.
- Section gap: `gap-5` / `mt-5`.
- Card padding: `p-4`; table rows `px-4 py-3`.
- Card radius: `0.875rem`; controls `0.5rem`; pills `rounded-full`.
- Shadows are subtle; elevation communicates surface, not decoration.
- The shell sidebar is 240px expanded and 64px collapsed on desktop.

## Components

| Component | File | Rules |
| --- | --- | --- |
| Button | `Components/Button.jsx` | primary/secondary/ghost/danger; sizes sm/md/lg |
| Field controls | `Components/Field.jsx` | label, hint, error; `aria-invalid` |
| Status badge | `Components/StatusBadge.jsx` | tone plus text; never colour alone |
| Confirmation | `Components/ConfirmDialog.jsx` | destructive operations always confirm |
| Empty state | `Components/EmptyState.jsx` | explain the resource and offer a real action |
| Page section | `Components/PageSection.jsx` | title, description, actions, body |
| Stat card | `Components/StatCard.jsx` | real number, caption and icon |
| Credentials | `Components/ResourceCredentials.jsx` | one-time reveal, copy URL/password |
| Breadcrumbs | `Components/Breadcrumbs.jsx` | route-aware, no dead links |
| Theme toggle | `Components/ThemeToggle.jsx` | sun/moon, persists preference |
| User menu | `Components/UserMenu.jsx` | keyboard dismiss, settings and sign out |
| Skeleton | `Components/Skeleton.jsx` | `.cf-skeleton` shimmer, reduced-motion safe |
| Icons | `Components/Icons.jsx` | one custom stroked set, no emoji |

## Navigation

- **Workspace:** Overview, Projects, Databases.
- **Infrastructure:** Servers.
- **Management:** Team, API tokens, Audit log, Settings.

Only implemented routes are shown. The sidebar supports collapse on desktop and
a modal drawer on mobile with Escape and an explicit close button.

## Interaction rules

- Every button calls a real backend action or navigates to a real route.
- Pending resource operations poll the real backend; status is never simulated.
- Destructive operations use confirmation and explain the consequence.
- Forms show server validation errors next to the field.
- Icons without text have accessible labels and title/tooltips.
- Focus is always visible through the global `:focus-visible` rule.
- Reduced motion disables non-essential animation through the global media query.

## Page states

Each page must account for loading, empty, error, success, pending, warning,
offline and permission-denied states where the backend can produce them. The
current Inertia pages use server-rendered props; no fake skeletons are shown on
completed responses.

## Known boundaries

Applications, deployments, domains, backups and networking have no backend
contracts yet, so they are not represented as working screens. Their eventual UI
must reuse this system rather than introduce a second component language.
