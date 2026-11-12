import { useMemo, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { AppLayout } from '../../Layouts/AppLayout';
import { EmptyState } from '../../Components/EmptyState';
import { Field, Input, Textarea } from '../../Components/Field';
import { Icon } from '../../Components/Icons';
import { StatCard } from '../../Components/StatCard';
import { StatusBadge } from '../../Components/StatusBadge';
import { buttonClass } from '../../Components/Button';
import { formatDate, initials } from '../../lib/format';
import { environmentTone } from '../../lib/status';

function ProjectCard({ project }) {
    const attention = (project.failed_resource_count ?? 0) > 0 || (project.failed_application_count ?? 0) > 0;
    const workloadCount = (project.resource_count ?? 0) + (project.application_count ?? 0);

    return (
        <div className="cf-card group flex flex-col p-4 transition-colors hover:border-accent/30">
            <div className="flex items-start justify-between gap-3">
                <Link href={`/projects/${project.id}`} className="flex min-w-0 items-start gap-2.5">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-line bg-surface-raised font-mono text-[11px] text-muted">
                        {initials(project.name)}
                    </span>
                    <span className="min-w-0">
                        <span className="block truncate text-sm font-semibold text-ink group-hover:text-accent">{project.name}</span>
                        <span className="mt-0.5 block font-mono text-[10px] text-faint">{project.slug}</span>
                    </span>
                </Link>
                <StatusBadge tone={attention ? 'danger' : workloadCount > 0 ? 'success' : 'info'} dot={false}>
                    {attention ? 'Attention' : workloadCount > 0 ? 'Healthy' : 'New'}
                </StatusBadge>
            </div>

            <p className="mt-2 line-clamp-2 min-h-8 text-xs leading-5 text-muted">
                {project.description || 'No description yet.'}
            </p>

            <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <div className="rounded-lg border border-line bg-surface-raised/50 px-2.5 py-2">
                    <p className="font-mono text-[9px] tracking-wide text-faint uppercase">Apps</p>
                    <p className="mt-0.5 text-base font-semibold text-ink tabular-nums">{project.application_count ?? 0}</p>
                </div>
                <div className="rounded-lg border border-line bg-surface-raised/50 px-2.5 py-2">
                    <p className="font-mono text-[9px] tracking-wide text-faint uppercase">Databases</p>
                    <p className="mt-0.5 text-base font-semibold text-ink tabular-nums">{project.resource_count}</p>
                </div>
                <div className="rounded-lg border border-line bg-surface-raised/50 px-2.5 py-2">
                    <p className="font-mono text-[9px] tracking-wide text-faint uppercase">Envs</p>
                    <p className="mt-0.5 text-base font-semibold text-ink tabular-nums">{project.environment_count}</p>
                </div>
                <div className="rounded-lg border border-line bg-surface-raised/50 px-2.5 py-2">
                    <p className="font-mono text-[9px] tracking-wide text-faint uppercase">Servers</p>
                    <p className="mt-0.5 text-base font-semibold text-ink tabular-nums">{project.server_count}</p>
                </div>
            </div>

            <div className="mt-3 flex flex-wrap gap-1.5">
                {project.environments.slice(0, 3).map((environment) => (
                    <StatusBadge key={environment.id} tone={environmentTone[environment.type] ?? 'neutral'} dot={false}>
                        {environment.name}
                    </StatusBadge>
                ))}
                {project.environments.length > 3 ? (
                    <span className="text-[10px] text-faint">+{project.environments.length - 3} more</span>
                ) : null}
                {project.environments.length === 0 ? <span className="text-[10px] text-faint">No environments</span> : null}
            </div>

            <div className="mt-auto flex items-center justify-between gap-2 border-t border-line pt-3">
                <span className="font-mono text-[10px] text-faint">created {formatDate(project.created_at)}</span>
                <div className="flex items-center gap-2">
                    <Link href={`/projects/${project.id}/settings`} className="text-[11px] font-medium text-faint hover:text-accent">
                        Settings
                    </Link>
                    <Link href={`/projects/${project.id}`} className="text-[11px] font-medium text-accent hover:text-accent-strong">
                        Open
                    </Link>
                </div>
            </div>
        </div>
    );
}

export default function ProjectsIndex({ projects, summary = {}, canCreate }) {
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState('recent');
    const form = useForm({ name: '', description: '' });

    const visible = useMemo(() => {
        const term = search.trim().toLowerCase();
        const filtered = projects.filter((project) => {
            if (!term) {
                return true;
            }

            return [project.name, project.description ?? '', project.slug].join(' ').toLowerCase().includes(term);
        });

        return [...filtered].sort((a, b) => {
            if (sort === 'name') {
                return a.name.localeCompare(b.name);
            }

            if (sort === 'oldest') {
                return new Date(a.created_at).getTime() - new Date(b.created_at).getTime();
            }

            return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
        });
    }, [projects, search, sort]);

    function submit(event) {
        event.preventDefault();
        form.post('/projects', {
            onSuccess: () => form.reset(),
        });
    }

    return (
        <AppLayout
            title="Projects"
            description="Organize your applications, databases and environments in one workspace."
            eyebrow="Workspace"
            actions={
                canCreate ? (
                    <a href="#new-project" className={buttonClass({ variant: 'primary' })}>
                        <Icon name="plus" size={15} />
                        Create project
                    </a>
                ) : null
            }
        >
            <Head title="Projects" />

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                <StatCard label="Projects" value={summary.projects ?? projects.length} caption="workspaces" icon="boxes" tone="accent" />
                <StatCard label="Applications" value={summary.applications ?? 0} caption={`${summary.active_applications ?? 0} active`} icon="activity" tone="success" />
                <StatCard label="Databases" value={summary.databases ?? 0} caption={`${summary.active_databases ?? 0} active`} icon="database" tone="success" />
                <StatCard label="Environments" value={summary.environments ?? 0} caption="deployment targets" icon="grid" tone="neutral" />
                <StatCard label="Healthy" value={projects.filter((project) => (project.failed_resource_count ?? 0) === 0 && (project.failed_application_count ?? 0) === 0).length} caption="projects clear" icon="check" tone="info" />
            </div>

            <div className="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]">
                <div>
                    <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                        <label className="relative min-w-0 flex-1">
                            <span className="sr-only">Search projects</span>
                            <Icon name="search" size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-faint" />
                            <input
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                className="cf-input h-9 py-0 pl-8 text-xs"
                                placeholder="Search projects"
                            />
                        </label>
                        <select value={sort} onChange={(event) => setSort(event.target.value)} className="cf-input h-9 py-0 text-xs sm:w-44" aria-label="Sort projects">
                            <option value="recent">Newest first</option>
                            <option value="name">Name</option>
                            <option value="oldest">Oldest first</option>
                        </select>
                    </div>

                    {projects.length === 0 ? (
                        <div className="cf-card">
                            <EmptyState
                                icon="boxes"
                                title="Your workspace starts with a project"
                                description="Create a project to organize your applications, databases and deployment environments."
                                action={
                                    canCreate ? (
                                        <a href="#new-project" className={buttonClass({ variant: 'primary', size: 'sm' })}>
                                            <Icon name="plus" size={14} />
                                            Create your first project
                                        </a>
                                    ) : null
                                }
                            />
                        </div>
                    ) : visible.length === 0 ? (
                        <div className="cf-card">
                            <EmptyState icon="search" title="No projects match" description="Try a different project name." />
                        </div>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2">
                            {visible.map((project) => (
                                <ProjectCard key={project.id} project={project} />
                            ))}
                        </div>
                    )}
                </div>

                {canCreate ? (
                    <aside id="new-project" className="scroll-mt-20">
                        <form onSubmit={submit} className="cf-card p-4">
                            <h2 className="text-sm font-semibold text-ink">New project</h2>
                            <p className="mt-0.5 text-xs text-faint">A production environment is created automatically.</p>

                            <div className="mt-4 space-y-3">
                                <Field label="Name" htmlFor="project-name" error={form.errors.name} required>
                                    <Input
                                        id="project-name"
                                        value={form.data.name}
                                        onChange={(event) => form.setData('name', event.target.value)}
                                        placeholder="Storefront"
                                        error={form.errors.name}
                                    />
                                </Field>
                                <Field label="Description" htmlFor="project-description" error={form.errors.description}>
                                    <Textarea
                                        id="project-description"
                                        value={form.data.description}
                                        onChange={(event) => form.setData('description', event.target.value)}
                                        placeholder="Customer-facing commerce platform"
                                    />
                                </Field>
                                <button type="submit" disabled={form.processing} className={buttonClass({ variant: 'primary', className: 'w-full' })}>
                                    <Icon name="plus" size={14} />
                                    {form.processing ? 'Creating…' : 'Create project'}
                                </button>
                            </div>
                        </form>

                        <div className="mt-4 flex items-start gap-2 rounded-lg border border-line bg-surface-raised px-3 py-2.5">
                            <Icon name="lock" size={14} className="mt-0.5 shrink-0 text-faint" />
                            <p className="text-[11px] leading-5 text-faint">
                                Environment slugs are generated per project and stay unique inside this organization.
                            </p>
                        </div>
                    </aside>
                ) : null}
            </div>
        </AppLayout>
    );
}
