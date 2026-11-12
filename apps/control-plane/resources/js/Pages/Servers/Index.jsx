import { Head, Link } from '@inertiajs/react';
import { AppLayout } from '../../Layouts/AppLayout';
import { StatusBadge } from '../../Components/StatusBadge';
import { Pagination } from '../../Components/Pagination';
import { EmptyState } from '../../Components/EmptyState';
import { Icon } from '../../Components/Icons';
import { buttonClass } from '../../Components/Button';
import { formatRelative, initials } from '../../lib/format';

export default function ServersIndex({ servers, canManage }) {
    return (
        <AppLayout
            title="Servers"
            description="Nodes enrolled in this organization, their lifecycle state and last known heartbeat."
            eyebrow="Infrastructure"
            actions={
                canManage ? (
                    <Link href="/servers/create" className={buttonClass({ variant: 'primary' })}>
                        <Icon name="plus" size={15} />
                        Register server
                    </Link>
                ) : null
            }
        >
            <Head title="Servers" />

            <section className="cf-card overflow-hidden">
                {servers.data.length === 0 ? (
                    <EmptyState
                        icon="server"
                        title="No servers registered"
                        description="Add a VPS, issue a single-use enrollment token and install the agent to bring it online."
                        action={
                            canManage ? (
                                <Link href="/servers/create" className={buttonClass({ variant: 'primary', size: 'sm' })}>
                                    <Icon name="plus" size={14} />
                                    Register your first server
                                </Link>
                            ) : null
                        }
                    />
                ) : (
                    <>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[760px] text-left">
                                <thead>
                                    <tr className="bg-surface-raised/50">
                                        <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Server</th>
                                        <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Status</th>
                                        <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Location</th>
                                        <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Roles</th>
                                        <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Agent</th>
                                        <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Last heartbeat</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {servers.data.map((server) => (
                                        <tr key={server.id} className="cf-table-row">
                                            <td className="px-4 py-3">
                                                <Link href={`/servers/${server.id}`} className="group flex items-center gap-2.5">
                                                    <span className="flex h-8 w-8 items-center justify-center rounded-md border border-line bg-surface-raised font-mono text-[10px] text-faint">
                                                        {initials(server.name)}
                                                    </span>
                                                    <span className="min-w-0">
                                                        <span className="flex items-center gap-1.5">
                                                            <span className="block truncate text-[13px] font-medium text-ink group-hover:text-accent">
                                                                {server.name}
                                                            </span>
                                                            {server.is_bootstrap ? (
                                                                <span className="shrink-0 rounded border border-accent/30 bg-accent-soft px-1 py-0.5 font-mono text-[9px] tracking-wide text-accent uppercase">
                                                                    bootstrap
                                                                </span>
                                                            ) : null}
                                                        </span>
                                                        <span className="block truncate font-mono text-[10px] text-faint">{server.hostname}</span>
                                                    </span>
                                                </Link>
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge tone={server.tone}>{server.status_label}</StatusBadge>
                                            </td>
                                            <td className="px-4 py-3">
                                                <p className="font-mono text-[11px] text-muted">{server.region ?? '—'}</p>
                                                <p className="font-mono text-[10px] text-faint">{server.public_ip ?? 'no public ip'}</p>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex max-w-[180px] flex-wrap gap-1">
                                                    {server.roles.map((role) => (
                                                        <span key={role} className="rounded border border-line px-1.5 py-0.5 font-mono text-[10px] text-muted">
                                                            {role}
                                                        </span>
                                                    ))}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <p className="font-mono text-[11px] text-muted">{server.agent_version ?? 'not installed'}</p>
                                                <p className="font-mono text-[10px] text-faint">docker {server.docker_version ?? '—'}</p>
                                            </td>
                                            <td className="px-4 py-3 text-xs text-muted">{formatRelative(server.last_heartbeat_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination paginator={servers} />
                    </>
                )}
            </section>

            <div className="mt-4 flex items-start gap-2 rounded-lg border border-line bg-surface-raised px-3.5 py-3">
                <Icon name="shield" size={15} className="mt-0.5 shrink-0 text-faint" />
                <p className="text-xs leading-5 text-faint">
                    Agents authenticate with certificates bound to a single server. Revoking a certificate blocks access
                    immediately; enrollment tokens are single-use and expire in minutes.
                </p>
            </div>
        </AppLayout>
    );
}
