import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AppLayout } from '../../Layouts/AppLayout';
import { ConfirmDialog } from '../../Components/ConfirmDialog';
import { EmptyState } from '../../Components/EmptyState';
import { Icon } from '../../Components/Icons';
import { PageSection } from '../../Components/PageSection';
import { ResourceCredentials } from '../../Components/ResourceCredentials';
import { StatCard } from '../../Components/StatCard';
import { StatusBadge } from '../../Components/StatusBadge';
import { Field, Input, Select, Textarea } from '../../Components/Field';
import { buttonClass } from '../../Components/Button';
import { formatDate, formatRelative, initials } from '../../lib/format';
import { environmentTone } from '../../lib/status';

const tabs = [
    { key: 'overview', label: 'Overview', href: (id) => `/projects/${id}` },
    { key: 'databases', label: 'Databases', href: (id) => `/projects/${id}/databases` },
    { key: 'environments', label: 'Environments', href: (id) => `/projects/${id}/environments` },
    { key: 'activity', label: 'Activity', href: (id) => `/projects/${id}/activity` },
    { key: 'settings', label: 'Settings', href: (id) => `/projects/${id}/settings` },
];

const pendingStatuses = ['provisioning', 'starting', 'stopping', 'refreshing', 'deleting'];

function EnvironmentCard({ project, environment, canManage, onRemove }) {
    return (
        <div className="rounded-xl border border-line bg-surface-raised/40 p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-2.5">
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-line bg-surface text-faint">
                        <Icon name="boxes" size={15} />
                    </span>
                    <div className="min-w-0">
                        <p className="truncate text-[13px] font-medium text-ink">{environment.name}</p>
                        <p className="mt-0.5 font-mono text-[10px] text-faint">{environment.slug}</p>
                    </div>
                </div>
                <StatusBadge tone={environmentTone[environment.type] ?? 'neutral'} dot={false}>
                    {environment.type_label}
                </StatusBadge>
            </div>

            <div className="mt-4 grid grid-cols-2 gap-2">
                <div className="rounded-lg border border-line bg-surface p-3">
                    <p className="cf-label">Databases</p>
                    <p className="mt-1 text-xl font-semibold text-ink tabular-nums">{environment.resource_count ?? 0}</p>
                </div>
                <div className="rounded-lg border border-line bg-surface p-3">
                    <p className="cf-label">Active</p>
                    <p className="mt-1 text-xl font-semibold text-ink tabular-nums">{environment.active_resource_count ?? 0}</p>
                </div>
            </div>

            <div className="mt-3 flex items-center justify-between gap-2">
                <Link href={`/projects/${project.id}/databases`} className="text-xs font-medium text-accent hover:text-accent-strong">
                    Manage databases
                </Link>
                {canManage ? (
                    <button
                        type="button"
                        onClick={() => onRemove(environment)}
                        className="rounded-md p-1.5 text-faint transition-colors hover:bg-danger/10 hover:text-danger"
                        aria-label={`Remove ${environment.name}`}
                    >
                        <Icon name="trash" size={14} />
                    </button>
                ) : null}
            </div>
        </div>
    );
}

function ResourceRow({ resource, onAction, onReveal, onRemove }) {
    return (
        <li className="flex flex-wrap items-center gap-3 px-4 py-3">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-line bg-surface-raised text-faint">
                <Icon name="database" size={15} />
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="text-[13px] font-medium text-ink">{resource.name}</p>
                    <StatusBadge tone={resource.status_tone}>{resource.status_label}</StatusBadge>
                </div>
                <p className="mt-0.5 font-mono text-[10px] text-faint">
                    {resource.type_label} · {resource.environment.name} · {resource.server.name}
                    {resource.port ? ` · ${resource.host}:${resource.port}` : ''}
                </p>
                {resource.last_error ? <p className="mt-1 line-clamp-2 text-[11px] text-danger">{resource.last_error}</p> : null}
            </div>
            <div className="flex flex-wrap items-center gap-1">
                {resource.can_reveal ? (
                    <button type="button" onClick={() => onReveal(resource)} className={buttonClass({ variant: 'secondary', size: 'sm' })}>
                        <Icon name="key" size={12} />
                        Credentials
                    </button>
                ) : null}
                {resource.can_start ? (
                    <button type="button" onClick={() => onAction(resource, 'start')} className="rounded-md p-1.5 text-faint hover:bg-surface-raised hover:text-ink" aria-label={`Start ${resource.name}`}>
                        <Icon name="play" size={14} />
                    </button>
                ) : null}
                {resource.can_stop ? (
                    <button type="button" onClick={() => onAction(resource, 'stop')} className="rounded-md p-1.5 text-faint hover:bg-surface-raised hover:text-ink" aria-label={`Stop ${resource.name}`}>
                        <Icon name="ban" size={14} />
                    </button>
                ) : null}
                {resource.can_refresh ? (
                    <button type="button" onClick={() => onAction(resource, 'refresh')} className="rounded-md p-1.5 text-faint hover:bg-surface-raised hover:text-ink" aria-label={`Refresh ${resource.name}`}>
                        <Icon name="refresh" size={14} />
                    </button>
                ) : null}
                {resource.can_delete ? (
                    <button type="button" onClick={() => onRemove(resource)} className="rounded-md p-1.5 text-faint hover:bg-danger/10 hover:text-danger" aria-label={`Delete ${resource.name}`}>
                        <Icon name="trash" size={14} />
                    </button>
                ) : null}
            </div>
        </li>
    );
}

export default function ProjectsShow({
    project,
    tab = 'overview',
    resources = [],
    servers = [],
    resourceTypes = [],
    environmentTypes = [],
    activity = [],
    stats = {},
    attention = [],
    canManage = false,
    canDelete = false,
    canCreateResource = false,
}) {
    const page = usePage();
    const credentials = page.props.flash?.resource_credentials ?? null;
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [removingEnvironment, setRemovingEnvironment] = useState(null);
    const [removingResource, setRemovingResource] = useState(null);
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [activityFilter, setActivityFilter] = useState('');

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

    const pending = resources.some((resource) => pendingStatuses.includes(resource.status));

    useEffect(() => {
        if (!pending || credentials) {
            return undefined;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['resources', 'stats', 'attention', 'activity'], preserveScroll: true });
        }, 3000);

        return () => window.clearInterval(timer);
    }, [pending, credentials]);

    const visibleResources = useMemo(() => {
        const term = search.trim().toLowerCase();

        return resources.filter((resource) => {
            if (typeFilter && resource.type !== typeFilter) {
                return false;
            }

            if (statusFilter && resource.status !== statusFilter) {
                return false;
            }

            if (!term) {
                return true;
            }

            return [resource.name, resource.environment.name, resource.server.name].join(' ').toLowerCase().includes(term);
        });
    }, [resources, search, typeFilter, statusFilter]);

    const visibleActivity = useMemo(() => {
        if (!activityFilter) {
            return activity;
        }

        return activity.filter((entry) => entry.action.includes(activityFilter));
    }, [activity, activityFilter]);

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

    function resourceAction(resource, action) {
        router.post(`/projects/${project.id}/resources/${resource.id}/${action}`, {}, { preserveScroll: true });
    }

    function revealResource(resource) {
        router.post(`/projects/${project.id}/resources/${resource.id}/reveal`, {}, { preserveScroll: true });
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

    function removeResource() {
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

    const activeTab = tabs.find((item) => item.key === tab) ?? tabs[0];

    return (
        <AppLayout
            title={project.name}
            renderHeader={false}
            description={project.description}
        >
            <Head title={`${project.name} · ${activeTab.label}`} />

            <div className="mb-5">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="flex min-w-0 items-start gap-3.5">
                        <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-accent/25 bg-accent-soft font-mono text-base font-medium text-accent">
                            {initials(project.name)}
                        </span>
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="truncate text-2xl font-semibold tracking-tight text-ink">{project.name}</h1>
                                <StatusBadge tone="neutral" dot={false}>
                                    {project.environment_count} environment{project.environment_count === 1 ? '' : 's'}
                                </StatusBadge>
                            </div>
                            <p className="mt-1 max-w-2xl text-sm leading-6 text-muted">
                                {project.description || 'No description yet.'}
                            </p>
                            <p className="mt-1.5 font-mono text-[10px] text-faint">
                                {project.slug} · created {formatDate(project.created_at)}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {canCreateResource ? (
                            <Link href={`/projects/${project.id}/databases`} className={buttonClass({ variant: 'primary' })}>
                                <Icon name="database" size={15} />
                                New database
                            </Link>
                        ) : null}
                        {canManage ? (
                            <Link href={`/projects/${project.id}/environments`} className={buttonClass({ variant: 'secondary' })}>
                                <Icon name="plus" size={15} />
                                New environment
                            </Link>
                        ) : null}
                        <Link href={`/projects/${project.id}/settings`} className={buttonClass({ variant: 'ghost' })}>
                            <Icon name="settings" size={15} />
                            Settings
                        </Link>
                    </div>
                </div>

                <nav className="mt-5 flex gap-1 overflow-x-auto border-b border-line" aria-label="Project sections">
                    {tabs.map((item) => {
                        const active = item.key === tab;

                        return (
                            <Link
                                key={item.key}
                                href={item.href(project.id)}
                                className={`relative shrink-0 px-3 py-2.5 text-[13px] transition-colors ${
                                    active ? 'text-ink' : 'text-faint hover:text-ink'
                                }`}
                                aria-current={active ? 'page' : undefined}
                            >
                                {item.label}
                                {active ? <span className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-accent" /> : null}
                            </Link>
                        );
                    })}
                </nav>
            </div>

            {tab === 'overview' ? (
                <div className="space-y-5">
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <StatCard
                            label="Databases"
                            value={stats.databases ?? 0}
                            caption={`${stats.active_databases ?? 0} active`}
                            icon="database"
                            tone="accent"
                        />
                        <StatCard
                            label="Environments"
                            value={stats.environments ?? project.environments.length}
                            caption="deployment targets"
                            icon="boxes"
                            tone="neutral"
                        />
                        <StatCard
                            label="Servers"
                            value={stats.servers ?? 0}
                            caption="hosting resources"
                            icon="server"
                            tone="neutral"
                        />
                        <StatCard
                            label="Attention"
                            value={stats.attention ?? attention.length}
                            caption={(stats.attention ?? attention.length) === 0 ? 'all clear' : 'needs review'}
                            icon="alert"
                            tone={(stats.attention ?? attention.length) === 0 ? 'success' : 'warning'}
                        />
                    </div>

                    <div className="grid gap-5 lg:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">
                        <div className="space-y-5">
                            <PageSection
                                title="Environments"
                                description="Deployment targets and the databases they contain."
                                actions={
                                    <Link href={`/projects/${project.id}/environments`} className="text-xs font-medium text-accent hover:text-accent-strong">
                                        Manage environments
                                    </Link>
                                }
                            >
                                {project.environments.length === 0 ? (
                                    <EmptyState
                                        icon="boxes"
                                        title="No environments"
                                        description="Create a development, staging or production target for this project."
                                    />
                                ) : (
                                    <div className="grid gap-3 p-4 sm:grid-cols-2">
                                        {project.environments.map((environment) => (
                                            <EnvironmentCard
                                                key={environment.id}
                                                project={project}
                                                environment={environment}
                                                canManage={canManage}
                                                onRemove={setRemovingEnvironment}
                                            />
                                        ))}
                                    </div>
                                )}
                            </PageSection>

                            <PageSection
                                title="Databases"
                                description="Managed PostgreSQL and Redis resources in this project."
                                actions={
                                    <Link href={`/projects/${project.id}/databases`} className="text-xs font-medium text-accent hover:text-accent-strong">
                                        View all
                                    </Link>
                                }
                            >
                                {resources.length === 0 ? (
                                    <EmptyState
                                        icon="database"
                                        title="No databases yet"
                                        description="Provision a managed database in the environment that needs it."
                                        action={
                                            canCreateResource ? (
                                                <Link href={`/projects/${project.id}/databases`} className={buttonClass({ variant: 'primary', size: 'sm' })}>
                                                    <Icon name="database" size={14} />
                                                    Provision database
                                                </Link>
                                            ) : null
                                        }
                                    />
                                ) : (
                                    <ul className="divide-y divide-line">
                                        {resources.slice(0, 4).map((resource) => (
                                            <ResourceRow
                                                key={resource.id}
                                                resource={resource}
                                                onAction={resourceAction}
                                                onReveal={revealResource}
                                                onRemove={setRemovingResource}
                                            />
                                        ))}
                                    </ul>
                                )}
                            </PageSection>
                        </div>

                        <div className="space-y-5">
                            <PageSection title="Attention required">
                                {attention.length === 0 ? (
                                    <div className="flex items-center gap-3 px-4 py-5">
                                        <span className="flex h-9 w-9 items-center justify-center rounded-lg border border-success/25 bg-success/10 text-success">
                                            <Icon name="check" size={17} />
                                        </span>
                                        <div>
                                            <p className="text-sm font-medium text-ink">No issues in this project</p>
                                            <p className="mt-0.5 text-xs text-faint">All managed resources are healthy.</p>
                                        </div>
                                    </div>
                                ) : (
                                    <ul className="divide-y divide-line">
                                        {attention.map((item) => (
                                            <li key={item.id} className="flex gap-3 px-4 py-3">
                                                <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-danger/25 bg-danger/10 text-danger">
                                                    <Icon name="alert" size={14} />
                                                </span>
                                                <div className="min-w-0">
                                                    <Link href={item.href} className="text-xs font-medium text-ink hover:text-accent">
                                                        {item.title}
                                                    </Link>
                                                    <p className="mt-0.5 line-clamp-2 text-[11px] leading-5 text-faint">{item.description}</p>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </PageSection>

                            <PageSection
                                title="Recent activity"
                                actions={
                                    <Link href={`/projects/${project.id}/activity`} className="text-xs font-medium text-accent hover:text-accent-strong">
                                        View all
                                    </Link>
                                }
                            >
                                {activity.length === 0 ? (
                                    <p className="px-4 py-8 text-center text-xs text-faint">No project activity yet.</p>
                                ) : (
                                    <ul className="divide-y divide-line">
                                        {activity.slice(0, 5).map((entry) => (
                                            <li key={entry.id} className="flex gap-2.5 px-4 py-2.5">
                                                <span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-line-strong" aria-hidden="true" />
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-xs text-ink">{entry.action_label}</p>
                                                    <p className="mt-0.5 text-[11px] text-faint">
                                                        {entry.actor} · {formatRelative(entry.created_at)}
                                                    </p>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </PageSection>
                        </div>
                    </div>
                </div>
            ) : null}

            {tab === 'databases' ? (
                <div className="space-y-5">
                    {credentials ? <ResourceCredentials credentials={credentials} /> : null}

                    <PageSection
                        title="Databases"
                        description="Loopback-only PostgreSQL and Redis resources in this project."
                    >
                        <div className="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-center">
                            <label className="relative min-w-0 flex-1">
                                <span className="sr-only">Search databases</span>
                                <Icon name="search" size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-faint" />
                                <input
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    className="cf-input h-9 py-0 pl-8 text-xs"
                                    placeholder="Search databases"
                                />
                            </label>
                            <select value={typeFilter} onChange={(event) => setTypeFilter(event.target.value)} className="cf-input h-9 py-0 text-xs sm:w-40" aria-label="Filter by engine">
                                <option value="">All engines</option>
                                {resourceTypes.map((resourceType) => (
                                    <option key={resourceType.value} value={resourceType.value}>
                                        {resourceType.label}
                                    </option>
                                ))}
                            </select>
                            <select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)} className="cf-input h-9 py-0 text-xs sm:w-40" aria-label="Filter by status">
                                <option value="">All statuses</option>
                                <option value="provisioning">Provisioning</option>
                                <option value="active">Active</option>
                                <option value="starting">Starting</option>
                                <option value="stopping">Stopping</option>
                                <option value="refreshing">Refreshing</option>
                                <option value="stopped">Stopped</option>
                                <option value="failed">Failed</option>
                                <option value="deleting">Deleting</option>
                            </select>
                        </div>

                        {visibleResources.length === 0 ? (
                            <EmptyState
                                icon="database"
                                title={resources.length === 0 ? 'No databases yet' : 'No databases match these filters'}
                                description={
                                    resources.length === 0
                                        ? 'Provision a managed database below, or switch to another environment.'
                                        : 'Clear the search or filters to see the other databases.'
                                }
                            />
                        ) : (
                            <ul className="divide-y divide-line">
                                {visibleResources.map((resource) => (
                                    <ResourceRow
                                        key={resource.id}
                                        resource={resource}
                                        onAction={resourceAction}
                                        onReveal={revealResource}
                                        onRemove={setRemovingResource}
                                    />
                                ))}
                            </ul>
                        )}
                    </PageSection>

                    {canCreateResource ? (
                        <PageSection title="Provision database" description="The database is created and started by the agent.">
                            <form onSubmit={addResource} className="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)_minmax(0,0.9fr)_minmax(0,0.9fr)_auto]">
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
                                    <Select id="resource-type" value={resourceForm.data.type} onChange={(event) => resourceForm.setData('type', event.target.value)}>
                                        {resourceTypes.map((resourceType) => (
                                            <option key={resourceType.value} value={resourceType.value}>
                                                {resourceType.label}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label="Environment" htmlFor="resource-environment" error={resourceForm.errors.environment_id} required>
                                    <Select id="resource-environment" value={resourceForm.data.environment_id} onChange={(event) => resourceForm.setData('environment_id', event.target.value)}>
                                        {project.environments.map((environment) => (
                                            <option key={environment.id} value={environment.id}>
                                                {environment.name}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label="Server" htmlFor="resource-server" error={resourceForm.errors.server_id} required>
                                    <Select id="resource-server" value={resourceForm.data.server_id} onChange={(event) => resourceForm.setData('server_id', event.target.value)}>
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
                                        No server is ready. Start the CloudForge agent before provisioning resources.
                                    </p>
                                ) : null}
                            </form>
                        </PageSection>
                    ) : null}
                </div>
            ) : null}

            {tab === 'environments' ? (
                <div className="space-y-5">
                    <PageSection title="Environments" description="Deployment targets inside this project.">
                        {project.environments.length === 0 ? (
                            <EmptyState icon="boxes" title="No environments" description="Add a development, staging or production target." />
                        ) : (
                            <div className="grid gap-3 p-4 sm:grid-cols-2">
                                {project.environments.map((environment) => (
                                    <EnvironmentCard
                                        key={environment.id}
                                        project={project}
                                        environment={environment}
                                        canManage={canManage}
                                        onRemove={setRemovingEnvironment}
                                    />
                                ))}
                            </div>
                        )}
                    </PageSection>

                    {canManage ? (
                        <PageSection title="Add environment" description="Environment names and types are independent per project.">
                            <form onSubmit={addEnvironment} className="grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)_auto]">
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
                                    <Select id="environment-type" value={environmentForm.data.type} onChange={(event) => environmentForm.setData('type', event.target.value)}>
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
                        </PageSection>
                    ) : null}
                </div>
            ) : null}

            {tab === 'activity' ? (
                <PageSection
                    title="Project activity"
                    description="Audit events for this project and its managed resources."
                    actions={
                        <select value={activityFilter} onChange={(event) => setActivityFilter(event.target.value)} className="cf-input h-9 py-0 text-xs" aria-label="Filter activity">
                            <option value="">All activity</option>
                            <option value="resource.">Databases</option>
                            <option value="project.">Project</option>
                            <option value="environment">Environments</option>
                        </select>
                    }
                >
                    {visibleActivity.length === 0 ? (
                        <EmptyState icon="scroll" title="No activity" description="Project changes and database operations will appear here." />
                    ) : (
                        <ul className="divide-y divide-line">
                            {visibleActivity.map((entry) => (
                                <li key={entry.id} className="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-center sm:gap-3">
                                    <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-line bg-surface-raised text-faint">
                                        <Icon name={entry.action.startsWith('resource.') ? 'database' : 'activity'} size={14} />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-[13px] font-medium text-ink">{entry.action_label}</p>
                                        <p className="mt-0.5 text-[11px] text-faint">
                                            {entry.actor} · {entry.resource_type ?? 'project'} · {formatRelative(entry.created_at)}
                                        </p>
                                    </div>
                                    <span className="font-mono text-[10px] text-faint">{formatDate(entry.created_at)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </PageSection>
            ) : null}

            {tab === 'settings' ? (
                <div className="grid gap-5 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                    <div className="space-y-5">
                        <PageSection title="General" description="Project identity and description.">
                            {canManage ? (
                                <form onSubmit={saveProject} className="space-y-3 p-4">
                                    <Field label="Name" htmlFor="name" error={editForm.errors.name} required>
                                        <Input id="name" value={editForm.data.name} onChange={(event) => editForm.setData('name', event.target.value)} error={editForm.errors.name} />
                                    </Field>
                                    <Field label="Description" htmlFor="description" error={editForm.errors.description}>
                                        <Textarea id="description" value={editForm.data.description} onChange={(event) => editForm.setData('description', event.target.value)} />
                                    </Field>
                                    <button type="submit" disabled={editForm.processing || !editForm.isDirty} className={buttonClass({ variant: 'primary' })}>
                                        {editForm.processing ? 'Saving…' : 'Save changes'}
                                    </button>
                                </form>
                            ) : (
                                <div className="space-y-2 p-4">
                                    <p className="text-sm text-muted">{project.description || 'No description.'}</p>
                                    <p className="font-mono text-[10px] text-faint">{project.slug}</p>
                                </div>
                            )}
                        </PageSection>

                        {canDelete ? (
                            <section className="cf-card border-danger/30 p-4">
                                <h2 className="text-sm font-semibold text-danger">Danger zone</h2>
                                <p className="mt-1 text-xs leading-5 text-muted">
                                    Deleting this project removes its environments. Projects with managed databases cannot be deleted until those resources are deleted.
                                </p>
                                <button type="button" onClick={() => setDeleteOpen(true)} className={buttonClass({ variant: 'danger', className: 'mt-3' })}>
                                    <Icon name="trash" size={14} />
                                    Delete project
                                </button>
                            </section>
                        ) : null}
                    </div>

                    <PageSection title="Metadata">
                        <div className="space-y-2 p-4 font-mono text-[11px] text-muted">
                            <p className="flex justify-between gap-3">
                                <span className="text-faint">slug</span>
                                <span className="truncate">{project.slug}</span>
                            </p>
                            <p className="flex justify-between gap-3">
                                <span className="text-faint">id</span>
                                <span className="truncate">{project.id}</span>
                            </p>
                            <p className="flex justify-between gap-3">
                                <span className="text-faint">environments</span>
                                <span>{project.environment_count}</span>
                            </p>
                            <p className="flex justify-between gap-3">
                                <span className="text-faint">databases</span>
                                <span>{project.resource_count}</span>
                            </p>
                            <p className="flex justify-between gap-3">
                                <span className="text-faint">created</span>
                                <span>{formatDate(project.created_at)}</span>
                            </p>
                        </div>
                    </PageSection>
                </div>
            ) : null}

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
                description="This environment will no longer be selectable. Delete its databases first."
                confirmLabel="Remove environment"
            />

            <ConfirmDialog
                open={removingResource !== null}
                onClose={() => setRemovingResource(null)}
                onConfirm={removeResource}
                title={`Delete ${removingResource?.name ?? 'database'}?`}
                description="The managed container and its persistent volume are removed. This cannot be undone."
                confirmLabel="Delete database"
            />
        </AppLayout>
    );
}
