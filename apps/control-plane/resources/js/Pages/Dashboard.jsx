import { Head, Link } from '@inertiajs/react';
import { AppLayout } from '../Layouts/AppLayout';
import { EmptyState } from '../Components/EmptyState';
import { Icon } from '../Components/Icons';
import { PageSection } from '../Components/PageSection';
import { StatCard } from '../Components/StatCard';
import { StatusBadge } from '../Components/StatusBadge';
import { buttonClass } from '../Components/Button';
import { formatRelative, initials } from '../lib/format';
import { serverStatusMeta } from '../lib/status';

function AttentionItem({ item }) {
    return (
        <li className="flex gap-3 px-4 py-3">
            <span
                className={`mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md border ${
                    item.tone === 'danger'
                        ? 'border-danger/25 bg-danger/10 text-danger'
                        : 'border-warning/25 bg-warning/10 text-warning'
                }`}
            >
                <Icon name={item.tone === 'danger' ? 'alert' : 'clock'} size={14} />
            </span>
            <div className="min-w-0 flex-1">
                <Link href={item.href} className="block truncate text-xs font-medium text-ink hover:text-accent">
                    {item.title}
                </Link>
                <p className="mt-0.5 line-clamp-2 text-[11px] leading-5 text-faint">{item.description}</p>
            </div>
        </li>
    );
}

export default function Dashboard({
    stats,
    resourceStats = { active: 0, failed: 0, provisioning: 0 },
    attention = [],
    statusCounts,
    servers,
    resources = [],
    recentAudit,
}) {
    const statusRows = Object.entries(serverStatusMeta).filter(([key]) => (statusCounts[key] ?? 0) > 0);

    return (
        <AppLayout
            title="Overview"
            description="Live infrastructure, managed databases and privileged activity for this organization."
            eyebrow="Control plane"
            actions={
                <>
                    <Link href="/projects" className={buttonClass({ variant: 'secondary' })}>
                        <Icon name="boxes" size={15} />
                        Projects
                    </Link>
                    <Link href="/servers/create" className={buttonClass({ variant: 'primary' })}>
                        <Icon name="plus" size={15} />
                        Add server
                    </Link>
                </>
            }
        >
            <Head title="Overview" />

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard
                    label="Servers"
                    value={stats.servers ?? 0}
                    caption={`${stats.healthy ?? 0} reachable`}
                    icon="server"
                    tone="accent"
                />
                <StatCard
                    label="Projects"
                    value={stats.projects ?? 0}
                    caption="delivery units"
                    icon="boxes"
                    tone="neutral"
                />
                <StatCard
                    label="Databases"
                    value={stats.databases ?? 0}
                    caption={`${resourceStats.active} active`}
                    icon="database"
                    tone={resourceStats.failed > 0 ? 'danger' : 'success'}
                />
                <StatCard
                    label="Attention"
                    value={attention.length}
                    caption={attention.length === 0 ? 'all clear' : 'items need review'}
                    icon="alert"
                    tone={attention.length === 0 ? 'success' : 'warning'}
                />
            </div>

            <div className="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]">
                <div className="space-y-5">
                    <PageSection
                        title="Attention required"
                        description="Real conditions reported by the agent or resource layer."
                    >
                        {attention.length === 0 ? (
                            <div className="flex items-center gap-3 px-4 py-5">
                                <span className="flex h-9 w-9 items-center justify-center rounded-lg border border-success/25 bg-success/10 text-success">
                                    <Icon name="check" size={17} />
                                </span>
                                <div>
                                    <p className="text-sm font-medium text-ink">No operational issues</p>
                                    <p className="mt-0.5 text-xs text-faint">Every reachable server and managed database is healthy.</p>
                                </div>
                            </div>
                        ) : (
                            <ul className="divide-y divide-line">
                                {attention.map((item) => (
                                    <AttentionItem key={item.id} item={item} />
                                ))}
                            </ul>
                        )}
                    </PageSection>

                    <PageSection
                        title="Fleet"
                        description="Most recently seen servers first."
                        actions={
                            <Link href="/servers" className="text-xs font-medium text-accent hover:text-accent-strong">
                                View all
                            </Link>
                        }
                    >
                        {servers.length === 0 ? (
                            <EmptyState
                                icon="server"
                                title="No servers yet"
                                description="Register a VPS and install the agent to start deploying workloads."
                                action={
                                    <Link href="/servers/create" className={buttonClass({ variant: 'primary', size: 'sm' })}>
                                        <Icon name="plus" size={14} />
                                        Register a server
                                    </Link>
                                }
                            />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[620px] text-left">
                                    <thead>
                                        <tr className="bg-surface-raised/50">
                                            <th className="px-4 py-2 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Server</th>
                                            <th className="px-4 py-2 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Status</th>
                                            <th className="px-4 py-2 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Heartbeat</th>
                                            <th className="px-4 py-2 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Docker</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {servers.map((server) => (
                                            <tr key={server.id} className="cf-table-row">
                                                <td className="px-4 py-3">
                                                    <Link href={`/servers/${server.id}`} className="group flex items-center gap-2.5">
                                                        <span className="flex h-7 w-7 items-center justify-center rounded-md border border-line bg-surface-raised font-mono text-[10px] text-faint">
                                                            {initials(server.name)}
                                                        </span>
                                                        <span className="min-w-0">
                                                            <span className="block truncate text-[13px] font-medium text-ink group-hover:text-accent">
                                                                {server.name}
                                                            </span>
                                                            <span className="block truncate font-mono text-[10px] text-faint">{server.hostname}</span>
                                                        </span>
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <StatusBadge tone={server.tone}>{server.status_label}</StatusBadge>
                                                </td>
                                                <td className="px-4 py-3 text-xs text-muted">{formatRelative(server.last_heartbeat_at)}</td>
                                                <td className="px-4 py-3 font-mono text-[11px] text-muted">
                                                    {server.docker_version || '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </PageSection>
                </div>

                <div className="space-y-5">
                    <PageSection title="Node states" description="Lifecycle distribution across the fleet.">
                        <div className="space-y-2.5 p-4">
                            {statusRows.length === 0 ? (
                                <p className="rounded-lg border border-dashed border-line px-3 py-4 text-center text-xs text-faint">
                                    No node states recorded yet.
                                </p>
                            ) : (
                                statusRows.map(([key, meta]) => {
                                    const count = statusCounts[key] ?? 0;
                                    const total = Math.max(1, stats.servers);
                                    const width = Math.round((count / total) * 100);

                                    return (
                                        <div key={key}>
                                            <div className="mb-1 flex items-center justify-between gap-2">
                                                <span className="flex items-center gap-1.5 text-xs text-muted">
                                                    <span
                                                        className={`h-1.5 w-1.5 rounded-full ${
                                                            meta.tone === 'success'
                                                                ? 'bg-success'
                                                                : meta.tone === 'danger'
                                                                  ? 'bg-danger'
                                                                  : meta.tone === 'warning'
                                                                    ? 'bg-warning'
                                                                    : 'bg-accent'
                                                        }`}
                                                    />
                                                    {meta.label}
                                                </span>
                                                <span className="font-mono text-[11px] text-faint tabular-nums">{count}</span>
                                            </div>
                                            <div className="h-1 overflow-hidden rounded-full bg-line">
                                                <div
                                                    className={`h-full rounded-full ${
                                                        meta.tone === 'success'
                                                            ? 'bg-success'
                                                            : meta.tone === 'danger'
                                                              ? 'bg-danger'
                                                              : meta.tone === 'warning'
                                                                ? 'bg-warning'
                                                                : 'bg-accent'
                                                    }`}
                                                    style={{ width: `${Math.max(4, width)}%` }}
                                                />
                                            </div>
                                        </div>
                                    );
                                })
                            )}
                        </div>
                    </PageSection>

                    <PageSection
                        title="Recent activity"
                        actions={
                            <Link href="/audit-log" className="text-xs font-medium text-accent hover:text-accent-strong">
                                Audit log
                            </Link>
                        }
                    >
                        {recentAudit.length === 0 ? (
                            <p className="px-4 py-8 text-center text-xs text-faint">No audit events yet.</p>
                        ) : (
                            <ul className="divide-y divide-line">
                                {recentAudit.map((entry) => (
                                    <li key={entry.id} className="flex gap-2.5 px-4 py-2.5">
                                        <span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-line-strong" aria-hidden="true" />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-xs text-ink">{entry.action}</p>
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

            <PageSection
                title="Managed databases"
                description="PostgreSQL and Redis resources provisioned through the agent."
                className="mt-5"
                actions={
                    <Link href="/databases" className="text-xs font-medium text-accent hover:text-accent-strong">
                        View all
                    </Link>
                }
            >
                {resources.length === 0 ? (
                    <EmptyState
                        icon="database"
                        title="No managed databases"
                        description="Open a project to provision a PostgreSQL or Redis resource for an environment."
                        action={
                            <Link href="/projects" className={buttonClass({ variant: 'secondary', size: 'sm' })}>
                                <Icon name="boxes" size={14} />
                                Open projects
                            </Link>
                        }
                    />
                ) : (
                    <ul className="divide-y divide-line">
                        {resources.map((resource) => (
                            <li key={resource.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                                <span className="flex h-8 w-8 items-center justify-center rounded-md border border-line bg-surface-raised text-faint">
                                    <Icon name="database" size={15} />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="flex flex-wrap items-center gap-2 text-[13px] font-medium text-ink">
                                        {resource.name}
                                        <StatusBadge tone={resource.status_tone}>{resource.status_label}</StatusBadge>
                                    </p>
                                    <p className="mt-0.5 font-mono text-[10px] text-faint">
                                        {resource.type_label} · {resource.project.name} · {resource.environment} · {resource.server}
                                    </p>
                                </div>
                                {resource.port ? (
                                    <code className="font-mono text-[11px] text-muted">127.0.0.1:{resource.port}</code>
                                ) : null}
                                <Link href={`/projects/${resource.project.id}`} className="text-xs font-medium text-accent hover:text-accent-strong">
                                    Open project
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </PageSection>
        </AppLayout>
    );
}
