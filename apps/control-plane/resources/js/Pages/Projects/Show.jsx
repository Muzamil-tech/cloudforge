import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AppLayout } from '../../Layouts/AppLayout';
import { StatusBadge } from '../../Components/StatusBadge';
import { Field, Input, Select, Textarea } from '../../Components/Field';
import { ConfirmDialog } from '../../Components/ConfirmDialog';
import { EmptyState } from '../../Components/EmptyState';
import { Icon } from '../../Components/Icons';
import { buttonClass } from '../../Components/Button';
import { formatDate } from '../../lib/format';
import { environmentTone } from '../../lib/status';

export default function ProjectsShow({ project, environmentTypes, canManage, canDelete }) {
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [removingEnvironment, setRemovingEnvironment] = useState(null);

    const editForm = useForm({
        name: project.name,
        description: project.description ?? '',
    });

    const environmentForm = useForm({
        name: '',
        type: environmentTypes[1]?.value ?? environmentTypes[0]?.value ?? 'production',
    });

    function saveProject(event) {
        event.preventDefault();
        editForm.patch(`/projects/${project.id}`, { preserveScroll: true });
    }

    function addEnvironment(event) {
        event.preventDefault();
        environmentForm.post(`/projects/${project.id}/environments`, {
            preserveScroll: true,
            onSuccess: () => environmentForm.reset('name'),
        });
    }

    function removeEnvironment() {
        if (!removingEnvironment) {
            return;
        }

        router.delete(`/projects/${project.id}/environments/${removingEnvironment.id}`, {
            preserveScroll: true,
            onFinish: () => setRemovingEnvironment(null),
        });
    }

    function deleteProject() {
        router.delete(`/projects/${project.id}`, {
            onFinish: () => setDeleteOpen(false),
        });
    }

    return (
        <AppLayout
            title={project.name}
            description={project.description || 'No description yet.'}
            eyebrow="Project"
            actions={
                <>
                    <Link href="/projects" className={buttonClass({ variant: 'ghost' })}>
                        <Icon name="arrowLeft" size={15} />
                        All projects
                    </Link>
                    {canDelete ? (
                        <button type="button" onClick={() => setDeleteOpen(true)} className={buttonClass({ variant: 'danger' })}>
                            <Icon name="trash" size={14} />
                            Delete
                        </button>
                    ) : null}
                </>
            }
        >
            <Head title={project.name} />

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                <section className="cf-card overflow-hidden">
                    <div className="flex items-center justify-between gap-2 border-b border-line px-4 py-3">
                        <div>
                            <h2 className="text-sm font-semibold text-ink">Environments</h2>
                            <p className="mt-0.5 text-xs text-faint">Deployment targets inside this project.</p>
                        </div>
                        <span className="font-mono text-[11px] text-faint">{project.environments.length}</span>
                    </div>

                    {project.environments.length === 0 ? (
                        <EmptyState icon="boxes" title="No environments" description="Add a development, staging or production target." />
                    ) : (
                        <ul className="divide-y divide-line">
                            {project.environments.map((environment) => (
                                <li key={environment.id} className="flex items-center gap-3 px-4 py-3">
                                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-line bg-surface-raised text-faint">
                                        <Icon name="boxes" size={15} />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="flex items-center gap-2 text-[13px] font-medium text-ink">
                                            {environment.name}
                                            <StatusBadge tone={environmentTone[environment.type] ?? 'neutral'} dot={false}>
                                                {environment.type_label}
                                            </StatusBadge>
                                        </p>
                                        <p className="mt-0.5 font-mono text-[10px] text-faint">
                                            {environment.slug} · created {formatDate(environment.created_at)}
                                        </p>
                                    </div>
                                    {canManage ? (
                                        <button
                                            type="button"
                                            onClick={() => setRemovingEnvironment(environment)}
                                            className="rounded-md p-1.5 text-faint transition-colors hover:bg-danger/10 hover:text-danger"
                                            aria-label={`Remove ${environment.name}`}
                                        >
                                            <Icon name="trash" size={15} />
                                        </button>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}

                    {canManage ? (
                        <form onSubmit={addEnvironment} className="grid gap-3 border-t border-line bg-surface-raised/30 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)_auto]">
                            <Field label="Environment name" htmlFor="environment-name" error={environmentForm.errors.name} required>
                                <Input
                                    id="environment-name"
                                    value={environmentForm.data.name}
                                    onChange={(event) => environmentForm.setData('name', event.target.value)}
                                    placeholder="staging-eu"
                                    error={environmentForm.errors.name}
                                />
                            </Field>
                            <Field label="Type" htmlFor="environment-type" error={environmentForm.errors.type} required>
                                <Select
                                    id="environment-type"
                                    value={environmentForm.data.type}
                                    onChange={(event) => environmentForm.setData('type', event.target.value)}
                                >
                                    {environmentTypes.map((type) => (
                                        <option key={type.value} value={type.value}>
                                            {type.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <div className="flex items-end">
                                <button type="submit" disabled={environmentForm.processing} className={buttonClass({ variant: 'primary', className: 'w-full' })}>
                                    <Icon name="plus" size={14} />
                                    Add
                                </button>
                            </div>
                        </form>
                    ) : null}
                </section>

                <aside className="space-y-5">
                    <section className="cf-card p-4">
                        <h2 className="text-sm font-semibold text-ink">Project details</h2>
                        <p className="mt-0.5 text-xs text-faint">Only owners, admins and developers can edit.</p>

                        {canManage ? (
                            <form onSubmit={saveProject} className="mt-4 space-y-3">
                                <Field label="Name" htmlFor="name" error={editForm.errors.name} required>
                                    <Input
                                        id="name"
                                        value={editForm.data.name}
                                        onChange={(event) => editForm.setData('name', event.target.value)}
                                        error={editForm.errors.name}
                                    />
                                </Field>
                                <Field label="Description" htmlFor="description" error={editForm.errors.description}>
                                    <Textarea
                                        id="description"
                                        value={editForm.data.description}
                                        onChange={(event) => editForm.setData('description', event.target.value)}
                                    />
                                </Field>
                                <button
                                    type="submit"
                                    disabled={editForm.processing || !editForm.isDirty}
                                    className={buttonClass({ variant: 'primary', className: 'w-full' })}
                                >
                                    {editForm.processing ? 'Saving…' : 'Save changes'}
                                </button>
                            </form>
                        ) : (
                            <div className="mt-3 space-y-2">
                                <p className="text-xs text-muted">{project.description || 'No description.'}</p>
                            </div>
                        )}
                    </section>

                    <section className="cf-card p-4">
                        <p className="cf-label mb-2">Metadata</p>
                        <div className="space-y-1.5 font-mono text-[11px] text-muted">
                            <p className="flex justify-between gap-3">
                                <span className="text-faint">slug</span>
                                <span className="truncate">{project.slug}</span>
                            </p>
                            <p className="flex justify-between gap-3">
                                <span className="text-faint">id</span>
                                <span className="truncate">{project.id}</span>
                            </p>
                            <p className="flex justify-between gap-3">
                                <span className="text-faint">created</span>
                                <span>{formatDate(project.created_at)}</span>
                            </p>
                        </div>
                    </section>
                </aside>
            </div>

            <ConfirmDialog
                open={deleteOpen}
                onClose={() => setDeleteOpen(false)}
                onConfirm={deleteProject}
                title={`Delete ${project.name}?`}
                description="The project and its environments are removed. Audit history is retained."
                confirmLabel="Delete project"
            />

            <ConfirmDialog
                open={removingEnvironment !== null}
                onClose={() => setRemovingEnvironment(null)}
                onConfirm={removeEnvironment}
                title={`Remove ${removingEnvironment?.name ?? 'environment'}?`}
                description="Workloads and deployments are not part of Phase 1, but this environment will no longer be selectable."
                confirmLabel="Remove environment"
            />
        </AppLayout>
    );
}
