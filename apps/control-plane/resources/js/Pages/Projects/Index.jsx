import { Head, Link, useForm } from '@inertiajs/react';
import { AppLayout } from '../../Layouts/AppLayout';
import { StatusBadge } from '../../Components/StatusBadge';
import { Field, Input, Textarea } from '../../Components/Field';
import { EmptyState } from '../../Components/EmptyState';
import { Icon } from '../../Components/Icons';
import { buttonClass } from '../../Components/Button';
import { formatDate } from '../../lib/format';
import { environmentTone, environmentTypeLabel } from '../../lib/status';

function ProjectCard({ project }) {
    return (
        <Link
            href={`/projects/${project.id}`}
            className="cf-card group flex flex-col p-4 transition-colors hover:border-accent/30"
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <h3 className="truncate text-sm font-semibold text-ink group-hover:text-accent">{project.name}</h3>
                    <p className="mt-0.5 font-mono text-[10px] text-faint">{project.slug}</p>
                </div>
                <Icon name="chevronRight" size={16} className="mt-0.5 shrink-0 text-faint group-hover:text-accent" />
            </div>

            <p className="mt-2 line-clamp-2 min-h-8 text-xs leading-5 text-muted">
                {project.description || 'No description yet.'}
            </p>

            <div className="mt-3 flex flex-wrap gap-1.5">
                {project.environments.map((environment) => (
                    <StatusBadge key={environment.id} tone={environmentTone[environment.type] ?? 'neutral'} dot={false}>
                        {environment.name}
                    </StatusBadge>
                ))}
                {project.environments.length === 0 ? (
                    <span className="text-[11px] text-faint">No environments</span>
                ) : null}
            </div>

            <p className="mt-auto border-t border-line pt-3 font-mono text-[10px] text-faint">
                created {formatDate(project.created_at)}
            </p>
        </Link>
    );
}

export default function ProjectsIndex({ projects, canCreate }) {
    const form = useForm({ name: '', description: '' });

    function submit(event) {
        event.preventDefault();
        form.post('/projects', {
            onSuccess: () => form.reset(),
        });
    }

    return (
        <AppLayout
            title="Projects"
            description="Group related environments and services into delivery units."
            eyebrow="Delivery"
        >
            <Head title="Projects" />

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                <div>
                    {projects.length === 0 ? (
                        <div className="cf-card">
                            <EmptyState
                                icon="boxes"
                                title="No projects yet"
                                description="Projects collect environments such as production, staging and development."
                            />
                        </div>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2">
                            {projects.map((project) => (
                                <ProjectCard key={project.id} project={project} />
                            ))}
                        </div>
                    )}
                </div>

                {canCreate ? (
                    <aside>
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
