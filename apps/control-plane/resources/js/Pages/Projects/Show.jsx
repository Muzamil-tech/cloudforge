import { useEffect, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AppLayout } from '../../Layouts/AppLayout';
import { StatusBadge } from '../../Components/StatusBadge';
import { Field, Input, Select, Textarea } from '../../Components/Field';
import { ConfirmDialog } from '../../Components/ConfirmDialog';
import { CopyField } from '../../Components/CopyField';
import { EmptyState } from '../../Components/EmptyState';
import { Icon } from '../../Components/Icons';
import { buttonClass } from '../../Components/Button';
import { formatDate } from '../../lib/format';
import { environmentTone } from '../../lib/status';

export default function ProjectsShow({
    project,
    resources = [],
    servers = [],
    resourceTypes = [],
    canManage,
    canDelete,
    canCreateResource = false,
}) {
    const page = usePage();
    const credentials = page.props.flash?.resource_credentials ?? null;
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [removingEnvironment, setRemovingEnvironment] = useState(null);
    const [removingResource, setRemovingResource] = useState(null);

    const editForm = useForm({
        name: project.name,
        description: project.description ?? '',
    });

    const environmentForm = useForm({
        name: '',
        type: environmentTypes[1]?.value ?? environmentTypes[0]?.value ?? 'production',
    });

    const resourceForm = useForm({
        name: '',
        type: resourceTypes[0]?.value ?? 'postgresql',
        environment_id: project.environments[0]?.id ?? '',
        server_id: servers[0]?.id ?? '',
    });

    const resourcesPending = resources.some((resource) =>
        ['provisioning', 'starting', 'stopping', 'refreshing', 'deleting'].includes(resource.status),
    );

    useEffect(() => {
        if (!resourcesPending || credentials) {
            return undefined;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['resources'], preserveScroll: true });
        }, 3000);

        return () => window.clearInterval(timer);
    }, [resourcesPending, credentials]);

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

    function addResource(event) {
        event.preventDefault();
        resourceForm.post(`/projects/${project.id}/resources`, {
            preserveScroll: true,
            onSuccess: () => resourceForm.reset('name'),
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

    function resourceAction(resource, action) {
        router.post(`/projects/${project.id}/resources/${resource.id}/${action}`, {}, { preserveScroll: true });
    }

    function revealResource(resource) {
        router.post(`/projects/${project.id}/resources/${resource.id}/reveal`, {}, { preserveScroll: true });
    }

    function deleteResource() {
        if (!removingResource) {
            return;
        }

        router.delete(`/projects/${project.id}/resources/${removingResource.id}`, {
            preserveScroll: true,
            onFinish: () => setRemovingResource(null),
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

            <section className="cf-card mt-5 overflow-hidden">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
                    <div>
                        <h2 className="text-sm font-semibold text-ink">Resources</h2>
                        <p className="mt-0.5 text-xs text-faint">
                            Managed PostgreSQL and Redis containers in this project.
                        </p>
                    </div>
                    <span className="font-mono text-[11px] text-faint">{resources.length}</span>
                </div>

                {credentials ? (
                    <div className="m-4 rounded-xl border border-success/30 bg-success/10 p-4">
                        <div className="flex items-start gap-2">
                            <Icon name="key" size={16} className="mt-0.5 shrink-0 text-success" />
                            <div>
                                <p className="text-sm font-semibold text-success">
                                    Credentials for {credentials.name}
                                </p>
                                <p className="mt-1 text-xs leading-5 text-muted">
                                    These credentials are shown once. Copy them now. They will not be shown again.
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 grid gap-3 lg:grid-cols-2">
                            <CopyField label="Connection URL" value={credentials.url} />
                            <CopyField label="Password" value={credentials.password} />
                        </div>
                    </div>
                ) : null}

                {resources.length === 0 ? (
                    <EmptyState
                        icon="database"
                        title="No resources yet"
                        description="Provision a managed PostgreSQL or Redis container for this project."
                    />
                ) : (
                    <ul className="divide-y divide-line">
                        {resources.map((resource) => (
                            <li key={resource.id} className="px-4 py-4">
                                <div className="flex flex-wrap items-start gap-3">
                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-line bg-surface-raised text-faint">
                                        <Icon name="database" size={17} />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="text-[13px] font-medium text-ink">{resource.name}</p>
                                            <StatusBadge tone="neutral" dot={false}>
                                                {resource.type_label}
                                            </StatusBadge>
                                            <StatusBadge tone={resource.status_tone}>{resource.status_label}</StatusBadge>
                                        </div>
                                        <p className="mt-1 font-mono text-[10px] text-faint">
                                            {resource.environment.name} · {resource.server.name}
                                            {resource.port ? ` · ${resource.host}:${resource.port}` : ''}
                                        </p>
                                        {resource.status === 'active' && resource.port ? (
                                            <p className="mt-1 font-mono text-[11px] text-muted">
                                                {resource.type === 'postgresql'
                                                    ? `postgresql://${resource.username ?? 'cloudforge'}@${resource.host}:${resource.port}/${resource.database_name ?? 'cloudforge'}`
                                                    : `redis://${resource.host}:${resource.port}/0`}
                                            </p>
                                        ) : null}
                                        {resource.last_error ? (
                                            <p className="mt-2 max-w-2xl text-xs leading-5 text-danger">{resource.last_error}</p>
                                        ) : null}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        {resource.can_reveal ? (
                                            <button
                                                type="button"
                                                onClick={() => revealResource(resource)}
                                                className={buttonClass({ variant: 'secondary', size: 'sm' })}
                                            >
                                                <Icon name="key" size={13} />
                                                Credentials
                                            </button>
                                        ) : null}
                                        {resource.can_start ? (
                                            <button
                                                type="button"
                                                onClick={() => resourceAction(resource, 'start')}
                                                className={buttonClass({ variant: 'ghost', size: 'sm' })}
                                            >
                                                <Icon name="play" size={13} />
                                                Start
                                            </button>
                                        ) : null}
                                        {resource.can_stop ? (
                                            <button
                                                type="button"
                                                onClick={() => resourceAction(resource, 'stop')}
                                                className={buttonClass({ variant: 'ghost', size: 'sm' })}
                                            >
                                                <Icon name="ban" size={13} />
                                                Stop
                                            </button>
                                        ) : null}
                                        {resource.can_refresh ? (
                                            <button
                                                type="button"
                                                onClick={() => resourceAction(resource, 'refresh')}
                                                className={buttonClass({ variant: 'ghost', size: 'sm' })}
                                                aria-label={`Refresh ${resource.name}`}
                                            >
                                                <Icon name="refresh" size={13} />
                                            </button>
                                        ) : null}
                                        {resource.can_delete ? (
                                            <button
                                                type="button"
                                                onClick={() => setRemovingResource(resource)}
                                                className="rounded-md p-1.5 text-faint transition-colors hover:bg-danger/10 hover:text-danger"
                                                aria-label={`Delete ${resource.name}`}
                                            >
                                                <Icon name="trash" size={15} />
                                            </button>
                                        ) : null}
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {canCreateResource ? (
                    <form
                        onSubmit={addResource}
                        className="grid gap-3 border-t border-line bg-surface-raised/30 p-4 md:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)_minmax(0,0.9fr)_minmax(0,0.9fr)_auto]"
                    >
                        <Field label="Resource name" htmlFor="resource-name" error={resourceForm.errors.name} required>
                            <Input
                                id="resource-name"
                                value={resourceForm.data.name}
                                onChange={(event) => resourceForm.setData('name', event.target.value)}
                                placeholder="main-db"
                                error={resourceForm.errors.name}
                            />
                        </Field>
                        <Field label="Type" htmlFor="resource-type" error={resourceForm.errors.type} required>
                            <Select
                                id="resource-type"
                                value={resourceForm.data.type}
                                onChange={(event) => resourceForm.setData('type', event.target.value)}
                            >
                                {resourceTypes.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field
                            label="Environment"
                            htmlFor="resource-environment"
                            error={resourceForm.errors.environment_id}
                            required
                        >
                            <Select
                                id="resource-environment"
                                value={resourceForm.data.environment_id}
                                onChange={(event) => resourceForm.setData('environment_id', event.target.value)}
                            >
                                {project.environments.map((environment) => (
                                    <option key={environment.id} value={environment.id}>
                                        {environment.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Server" htmlFor="resource-server" error={resourceForm.errors.server_id} required>
                            <Select
                                id="resource-server"
                                value={resourceForm.data.server_id}
                                onChange={(event) => resourceForm.setData('server_id', event.target.value)}
                            >
                                {servers.map((server) => (
                                    <option key={server.id} value={server.id}>
                                        {server.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <div className="flex items-end">
                            <button
                                type="submit"
                                disabled={resourceForm.processing || servers.length === 0 || project.environments.length === 0}
                                className={buttonClass({ variant: 'primary', className: 'w-full' })}
                            >
                                <Icon name="plus" size={14} />
                                Provision
                            </button>
                        </div>
                        {servers.length === 0 ? (
                            <p className="text-xs text-warning md:col-span-2 xl:col-span-5">
                                No server is ready. Start the CloudForge agent on a server before provisioning resources.
                            </p>
                        ) : null}
                    </form>
                ) : null}
            </section>

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

            <ConfirmDialog
                open={removingResource !== null}
                onClose={() => setRemovingResource(null)}
                onConfirm={deleteResource}
                title={`Delete ${removingResource?.name ?? 'resource'}?`}
                description="The managed container and its persistent volume are removed. This cannot be undone."
                confirmLabel="Delete resource"
            />
        </AppLayout>
    );
}
