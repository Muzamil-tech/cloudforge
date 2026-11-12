import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AppLayout } from '../../Layouts/AppLayout';
import { ConfirmDialog } from '../../Components/ConfirmDialog';
import { EmptyState } from '../../Components/EmptyState';
import { Icon } from '../../Components/Icons';
import { ResourceCredentials } from '../../Components/ResourceCredentials';
import { StatCard } from '../../Components/StatCard';
import { StatusBadge } from '../../Components/StatusBadge';
import { buttonClass } from '../../Components/Button';
import { formatDate } from '../../lib/format';

const pendingStatuses = ['provisioning', 'starting', 'stopping', 'refreshing', 'deleting'];

export default function DatabasesIndex({ resources = [], resourceTypes = [], canProvision = false, hasProjects = false }) {
    const page = usePage();
    const credentials = page.props.flash?.resource_credentials ?? null;
    const [search, setSearch] = useState('');
    const [type, setType] = useState('');
    const [status, setStatus] = useState('');
    const [sort, setSort] = useState('newest');
    const [removing, setRemoving] = useState(null);

    const pending = resources.some((resource) => pendingStatuses.includes(resource.status));

    useEffect(() => {
        if (!pending || credentials) {
            return undefined;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['resources'], preserveScroll: true });
        }, 3000);

        return () => window.clearInterval(timer);
    }, [pending, credentials]);

    const counts = useMemo(() => {
        return {
            total: resources.length,
            active: resources.filter((resource) => resource.status === 'active').length,
            failed: resources.filter((resource) => resource.status === 'failed').length,
            pending: resources.filter((resource) => pendingStatuses.includes(resource.status)).length,
        };
    }, [resources]);

    const visible = useMemo(() => {
        const term = search.trim().toLowerCase();

        const filtered = resources.filter((resource) => {
            if (type && resource.type !== type) {
                return false;
            }

            if (status && resource.status !== status) {
                return false;
            }

            if (!term) {
                return true;
            }

            return [resource.name, resource.project.name, resource.environment.name, resource.server.name]
                .join(' ')
                .toLowerCase()
                .includes(term);
        });

        return [...filtered].sort((a, b) => {
            if (sort === 'name') {
                return a.name.localeCompare(b.name);
            }

            if (sort === 'status') {
                return a.status.localeCompare(b.status);
            }

            return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
        });
    }, [resources, search, type, status, sort]);

    function resourceAction(resource, action) {
        router.post(`/projects/${resource.project.id}/resources/${resource.id}/${action}`, {}, { preserveScroll: true });
    }

    function reveal(resource) {
        router.post(`/projects/${resource.project.id}/resources/${resource.id}/reveal`, {}, { preserveScroll: true });
    }

    function remove() {
        if (!removing) {
            return;
        }

        router.delete(`/projects/${removing.project.id}/resources/${removing.id}`, {
            preserveScroll: true,
            onFinish: () => setRemoving(null),
        });
    }

    return (
        <AppLayout
            title="Databases"
            description="Managed PostgreSQL and Redis containers across your projects and environments."
            eyebrow="Workspace"
            actions={
                hasProjects && canProvision ? (
                    <Link href="/projects" className={buttonClass({ variant: 'primary' })}>
                        <Icon name="database" size={15} />
                        Provision from a project
                    </Link>
                ) : (
                    <Link href="/projects" className={buttonClass({ variant: 'secondary' })}>
                        <Icon name="boxes" size={15} />
                        {hasProjects ? 'Open projects' : 'Create a project'}
                    </Link>
                )
            }
        >
            <Head title="Databases" />

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard label="Databases" value={counts.total} caption="managed resources" icon="database" tone="accent" />
                <StatCard label="Active" value={counts.active} caption="running now" icon="activity" tone="success" />
                <StatCard label="Provisioning" value={counts.pending} caption="in operation" icon="clock" tone="info" />
                <StatCard label="Failed" value={counts.failed} caption="need attention" icon="alert" tone={counts.failed > 0 ? 'danger' : 'neutral'} />
            </div>

            {credentials ? (
                <div className="mt-5">
                    <ResourceCredentials credentials={credentials} />
                </div>
            ) : null}

            <section className="cf-card mt-5 overflow-hidden">
                <div className="flex flex-col gap-3 border-b border-line p-4 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <h2 className="text-sm font-semibold text-ink">All databases</h2>
                        <p className="mt-0.5 text-xs text-faint">Connection ports are bound to the server loopback interface.</p>
                    </div>
                    <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.4fr)_repeat(3,minmax(0,0.8fr))]">
                        <label className="relative">
                            <span className="sr-only">Search databases</span>
                            <Icon name="search" size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-faint" />
                            <input
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                className="cf-input h-9 py-0 pl-8 text-xs"
                                placeholder="Search name, project or server"
                            />
                        </label>
                        <select value={type} onChange={(event) => setType(event.target.value)} className="cf-input h-9 py-0 text-xs" aria-label="Filter by engine">
                            <option value="">All engines</option>
                            {resourceTypes.map((resourceType) => (
                                <option key={resourceType.value} value={resourceType.value}>
                                    {resourceType.label}
                                </option>
                            ))}
                        </select>
                        <select value={status} onChange={(event) => setStatus(event.target.value)} className="cf-input h-9 py-0 text-xs" aria-label="Filter by status">
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
                        <select value={sort} onChange={(event) => setSort(event.target.value)} className="cf-input h-9 py-0 text-xs" aria-label="Sort databases">
                            <option value="newest">Newest first</option>
                            <option value="name">Name</option>
                            <option value="status">Status</option>
                        </select>
                    </div>
                </div>

                {visible.length === 0 ? (
                    <EmptyState
                        icon="database"
                        title={resources.length === 0 ? 'No databases yet' : 'No databases match these filters'}
                        description={
                            resources.length === 0
                                ? 'Open a project and provision a managed PostgreSQL or Redis resource in the environment that needs it.'
                                : 'Clear the search or filters to see the other managed databases.'
                        }
                        action={
                            resources.length === 0 ? (
                                <Link href="/projects" className={buttonClass({ variant: 'primary', size: 'sm' })}>
                                    <Icon name="boxes" size={14} />
                                    Open projects
                                </Link>
                            ) : null
                        }
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[980px] text-left">
                            <thead>
                                <tr className="bg-surface-raised/50">
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Database</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Project</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Server</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Status</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Connection</th>
                                    <th className="px-4 py-2.5 text-right font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((resource) => (
                                    <tr key={resource.id} className="cf-table-row">
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2.5">
                                                <span className="flex h-8 w-8 items-center justify-center rounded-md border border-line bg-surface-raised text-faint">
                                                    <Icon name="database" size={15} />
                                                </span>
                                                <div className="min-w-0">
                                                    <p className="truncate text-[13px] font-medium text-ink">{resource.name}</p>
                                                    <p className="mt-0.5 font-mono text-[10px] text-faint">
                                                        {resource.type_label} · {resource.environment.name} · {formatDate(resource.created_at)}
                                                    </p>
                                                    {resource.last_error ? (
                                                        <p className="mt-1 max-w-md truncate text-[11px] text-danger" title={resource.last_error}>
                                                            {resource.last_error}
                                                        </p>
                                                    ) : null}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link href={`/projects/${resource.project.id}`} className="text-xs font-medium text-muted hover:text-accent">
                                                {resource.project.name}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="text-xs text-muted">{resource.server.name}</p>
                                            <p className="mt-0.5 font-mono text-[10px] text-faint">{resource.host}</p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge tone={resource.status_tone}>{resource.status_label}</StatusBadge>
                                        </td>
                                        <td className="px-4 py-3">
                                            {resource.port ? (
                                                <code className="font-mono text-[11px] text-muted">
                                                    {resource.host}:{resource.port}
                                                </code>
                                            ) : (
                                                <span className="text-[11px] text-faint">Not assigned</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap items-center justify-end gap-1">
                                                {resource.can_reveal ? (
                                                    <button type="button" onClick={() => reveal(resource)} className={buttonClass({ variant: 'secondary', size: 'sm' })}>
                                                        <Icon name="key" size={12} />
                                                        {resource.credentials_revealed ? 'View credentials' : 'Credentials'}
                                                    </button>
                                                ) : null}
                                                {resource.can_recover ? (
                                                    <button type="button" onClick={() => resourceAction(resource, 'recover')} className={buttonClass({ variant: 'secondary', size: 'sm' })}>
                                                        <Icon name="refresh" size={12} />
                                                        {resource.status === 'failed' ? 'Retry provisioning' : 'Recover credentials'}
                                                    </button>
                                                ) : null}
                                                {resource.can_start ? (
                                                    <button type="button" onClick={() => resourceAction(resource, 'start')} className="rounded-md p-1.5 text-faint hover:bg-surface-raised hover:text-ink" aria-label={`Start ${resource.name}`}>
                                                        <Icon name="play" size={14} />
                                                    </button>
                                                ) : null}
                                                {resource.can_stop ? (
                                                    <button type="button" onClick={() => resourceAction(resource, 'stop')} className="rounded-md p-1.5 text-faint hover:bg-surface-raised hover:text-ink" aria-label={`Stop ${resource.name}`}>
                                                        <Icon name="ban" size={14} />
                                                    </button>
                                                ) : null}
                                                {resource.can_refresh ? (
                                                    <button type="button" onClick={() => resourceAction(resource, 'refresh')} className="rounded-md p-1.5 text-faint hover:bg-surface-raised hover:text-ink" aria-label={`Refresh ${resource.name}`}>
                                                        <Icon name="refresh" size={14} />
                                                    </button>
                                                ) : null}
                                                {resource.can_delete ? (
                                                    <button type="button" onClick={() => setRemoving(resource)} className="rounded-md p-1.5 text-faint hover:bg-danger/10 hover:text-danger" aria-label={`Delete ${resource.name}`}>
                                                        <Icon name="trash" size={14} />
                                                    </button>
                                                ) : null}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            <ConfirmDialog
                open={removing !== null}
                onClose={() => setRemoving(null)}
                onConfirm={remove}
                title={`Delete ${removing?.name ?? 'database'}?`}
                description="The managed container and its persistent volume are removed. This cannot be undone."
                confirmLabel="Delete database"
            />
        </AppLayout>
    );
}
