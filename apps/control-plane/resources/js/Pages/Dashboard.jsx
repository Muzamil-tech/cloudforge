import { Head, Link } from '@inertiajs/react';
import { AppLayout } from '../Layouts/AppLayout';
import { StatusBadge } from '../Components/StatusBadge';
import { EmptyState } from '../Components/EmptyState';
import { Icon } from '../Components/Icons';
import { buttonClass } from '../Components/Button';
import { formatRelative, initials, formatDate } from '../lib/format';
import { serverStatusMeta } from '../lib/status';

const statCards = [
    { key: 'servers', label: 'Servers', icon: 'server', caption: 'registered nodes' },
    { key: 'healthy', label: 'Reachable', icon: 'activity', caption: 'healthy now' },
    { key: 'projects', label: 'Projects', icon: 'boxes', caption: 'delivery units' },
    { key: 'members', label: 'Members', icon: 'users', caption: 'active people' },
];

function StatCard({ item, value }) {
    return (
        <div className="cf-card p-4">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <p className="cf-label">{item.label}</p>
                    <p className="mt-2 text-3xl font-semibold tracking-tight text-ink tabular-nums">{value}</p>
                </div>
                <span className="flex h-8 w-8 items-center justify-center rounded-lg border border-line bg-surface-raised text-faint">
                    <Icon name={item.icon} size={16} />
                </span>
            </div>
            <p className="mt-2 text-[11px] text-faint">{item.caption}</p>
        </div>
    );
}

export default function Dashboard({ stats, statusCounts, servers, recentAudit }) {
    const statusRows = Object.entries(serverStatusMeta).filter(([key]) => (statusCounts[key] ?? 0) > 0);

    return (
        <AppLayout
            title="Overview"
            description="A live view of the fleet, delivery units and recent privileged activity."
            eyebrow="Control plane"
            actions={
                <>
                    <Link href="/projects" className={buttonClass({ variant: 'secondary', size: 'md' })}>
                        <Icon name="boxes" size={15} />
                        Projects
                    </Link>
                    <Link href="/servers/create" className={buttonClass({ variant: 'primary', size: 'md' })}>
                        <Icon name="plus" size={15} />
                        Add server
                    </Link>
                </>
            }
        >
            <Head title="Overview" />

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                {statCards.map((item) => (
                    <StatCard key={item.key} item={item} value={stats[item.key] ?? 0} />
                ))}
            </div>

            <div className="mt-6 grid gap-5 lg:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)]">
                <section className="cf-card overflow-hidden">
                    <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                        <div>
                            <h2 className="text-sm font-semibold text-ink">Fleet</h2>
                            <p className="mt-0.5 text-xs text-faint">Most recently seen servers first</p>
                        </div>
                        <Link href="/servers" className="text-xs font-medium text-accent hover:text-accent-strong">
                            View all
                        </Link>
                    </div>

                    {servers.length === 0 ? (
                        <EmptyState
                            icon="server"
                            title="No servers yet"
                            description="Register a VPS, issue a single-use enrollment token, then install the agent."
                            action={
                                <Link href="/servers/create" className={buttonClass({ variant: 'primary', size: 'sm' })}>
                                    <Icon name="plus" size={14} />
                                    Register a server
                                </Link>
                            }
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[560px] text-left">
                                <thead>
                                    <tr className="bg-surface-raised/50">
                                        <th className="px-4 py-2 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Server</th>
                                        <th className="px-4 py-2 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Status</th>
                                        <th className="px-4 py-2 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Heartbeat</th>
                                        <th className="px-4 py-2 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Roles</th>
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
                                            <td className="px-4 py-3">
                                                <div className="flex flex-wrap gap-1">
                                                    {server.roles.map((role) => (
                                                        <span key={role} className="rounded border border-line px-1.5 py-0.5 font-mono text-[10px] text-muted">
                                                            {role}
                                                        </span>
                                                    ))}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <div className="space-y-5">
                    <section className="cf-card p-4">
                        <h2 className="text-sm font-semibold text-ink">Node states</h2>
                        <p className="mt-0.5 text-xs text-faint">Lifecycle distribution across the organization</p>
                        <div className="mt-3 space-y-2.5">
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
                    </section>

                    <section className="cf-card overflow-hidden">
                        <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                            <h2 className="text-sm font-semibold text-ink">Recent activity</h2>
                            <Link href="/audit-log" className="text-xs font-medium text-accent hover:text-accent-strong">
                                Audit log
                            </Link>
                        </div>
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
                    </section>
                </div>
            </div>

            <p className="mt-6 flex items-center gap-1.5 font-mono text-[10px] tracking-wide text-faint uppercase">
                <Icon name="shield" size={12} />
                Phase 1 control plane · updated {formatDate(new Date().toISOString())}
            </p>
        </AppLayout>
    );
}
