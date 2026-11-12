import { Head, router } from '@inertiajs/react';
import { AppLayout } from '../../Layouts/AppLayout';
import { StatusBadge } from '../../Components/StatusBadge';
import { Field, Select } from '../../Components/Field';
import { Pagination } from '../../Components/Pagination';
import { EmptyState } from '../../Components/EmptyState';
import { Icon } from '../../Components/Icons';
import { buttonClass } from '../../Components/Button';
import { formatDate } from '../../lib/format';

function actionTone(action) {
    if (action.includes('revoked') || action.includes('removed') || action.includes('deleted')) {
        return 'danger';
    }

    if (action.includes('created') || action.includes('accepted') || action.includes('completed')) {
        return 'success';
    }

    if (action.includes('failed') || action.includes('denied')) {
        return 'warning';
    }

    return 'neutral';
}

function MetadataDetails({ value }) {
    if (!value || Object.keys(value).length === 0) {
        return <span className="text-xs text-faint">—</span>;
    }

    return (
        <details className="group">
            <summary className="cursor-pointer font-mono text-[10px] text-accent marker:content-none">
                <span className="group-open:hidden">view</span>
                <span className="hidden group-open:inline">hide</span>
            </summary>
            <pre className="mt-2 max-h-52 max-w-md overflow-auto rounded-md border border-line bg-canvas p-2.5 font-mono text-[10px] leading-4 text-muted">
                {JSON.stringify(value, null, 2)}
            </pre>
        </details>
    );
}

export default function AuditLogIndex({ logs, filters, actions }) {
    function applyFilter(event) {
        event.preventDefault();

        const form = new FormData(event.currentTarget);
        const action = String(form.get('action') ?? '');

        router.get('/audit-log', action ? { action } : {}, { preserveState: true, preserveScroll: true, replace: true });
    }

    return (
        <AppLayout
            title="Audit log"
            description="Every security-relevant action in this organization, recorded with actor and context."
            eyebrow="Governance"
        >
            <Head title="Audit log" />

            <section className="cf-card overflow-hidden">
                <div className="flex flex-col gap-3 border-b border-line px-4 py-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 className="text-sm font-semibold text-ink">Activity</h2>
                        <p className="mt-0.5 text-xs text-faint">
                            {logs.total} entr{logs.total === 1 ? 'y' : 'ies'} · newest first
                        </p>
                    </div>

                    <form onSubmit={applyFilter} className="flex items-end gap-2">
                        <Field label="Action" htmlFor="audit-action" className="w-56">
                            <Select id="audit-action" name="action" defaultValue={filters.action ?? ''} className="h-8 py-0 text-xs">
                                <option value="">All actions</option>
                                {actions.map((action) => (
                                    <option key={action.value} value={action.value}>
                                        {action.label}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <button type="submit" className={buttonClass({ variant: 'secondary', size: 'sm', className: 'mb-0.5' })}>
                            <Icon name="search" size={13} />
                            Filter
                        </button>
                        {filters.action ? (
                            <button
                                type="button"
                                onClick={() => router.get('/audit-log', {}, { preserveScroll: true, replace: true })}
                                className={buttonClass({ variant: 'ghost', size: 'sm', className: 'mb-0.5' })}
                            >
                                Clear
                            </button>
                        ) : null}
                    </form>
                </div>

                {logs.data.length === 0 ? (
                    <EmptyState
                        icon="scroll"
                        title="No audit entries"
                        description="Security-relevant actions such as logins, role changes, enrollments and revocations are recorded here."
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[860px] text-left">
                            <thead>
                                <tr className="bg-surface-raised/50">
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">When</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Action</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Actor</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Resource</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">IP</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                {logs.data.map((log) => (
                                    <tr key={log.id} className="cf-table-row align-top">
                                        <td className="whitespace-nowrap px-4 py-3 text-xs text-muted">{formatDate(log.created_at)}</td>
                                        <td className="px-4 py-3">
                                            <StatusBadge tone={actionTone(log.action)} dot={false}>
                                                {log.action_label}
                                            </StatusBadge>
                                            <p className="mt-1 font-mono text-[10px] text-faint">{log.action}</p>
                                        </td>
                                        <td className="px-4 py-3 text-xs text-ink">{log.actor}</td>
                                        <td className="px-4 py-3">
                                            {log.resource_type ? (
                                                <>
                                                    <p className="text-xs text-muted">{log.resource_type.split('\\').pop()}</p>
                                                    <p className="mt-0.5 max-w-40 truncate font-mono text-[10px] text-faint" title={log.resource_id ?? ''}>
                                                        {log.resource_id ?? '—'}
                                                    </p>
                                                </>
                                            ) : (
                                                <span className="text-xs text-faint">—</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 font-mono text-[11px] text-muted">{log.ip_address ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            <MetadataDetails value={log.metadata} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <Pagination paginator={logs} />
            </section>
        </AppLayout>
    );
}
