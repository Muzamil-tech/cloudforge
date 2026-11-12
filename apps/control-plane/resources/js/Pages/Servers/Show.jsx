import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AppLayout } from '../../Layouts/AppLayout';
import { StatusBadge } from '../../Components/StatusBadge';
import { Sparkline } from '../../Components/Sparkline';
import { CopyField } from '../../Components/CopyField';
import { ConfirmDialog } from '../../Components/ConfirmDialog';
import { Field, Input, Select } from '../../Components/Field';
import { EmptyState } from '../../Components/EmptyState';
import { Icon } from '../../Components/Icons';
import { buttonClass } from '../../Components/Button';
import {
    formatBytes,
    formatDate,
    formatPercent,
    formatRelative,
    duration,
} from '../../lib/format';
import { commandStatusTone } from '../../lib/status';

function MetricGauge({ label, icon, value, detail }) {
    const numeric = Math.min(100, Math.max(0, Number(value ?? 0)));
    const tone = numeric >= 85 ? 'bg-danger' : numeric >= 70 ? 'bg-warning' : 'bg-accent';

    return (
        <div className="rounded-lg border border-line bg-surface-raised p-3.5">
            <div className="flex items-center justify-between gap-2">
                <span className="flex items-center gap-1.5 text-[11px] text-muted">
                    <Icon name={icon} size={13} className="text-faint" />
                    {label}
                </span>
                <span className="font-mono text-sm font-medium text-ink tabular-nums">{formatPercent(value)}</span>
            </div>
            <div className="mt-2.5 h-1.5 overflow-hidden rounded-full bg-line">
                <div className={`h-full rounded-full ${tone}`} style={{ width: `${numeric}%` }} />
            </div>
            <p className="mt-2 font-mono text-[10px] text-faint">{detail}</p>
        </div>
    );
}

function InfoRow({ label, value, mono = true }) {
    return (
        <div className="flex items-start justify-between gap-3 border-t border-line py-2 first:border-t-0">
            <span className="shrink-0 font-mono text-[10px] tracking-[0.1em] text-faint uppercase">{label}</span>
            <span className={`min-w-0 text-right text-xs text-ink ${mono ? 'font-mono' : ''} break-all`}>{value ?? '—'}</span>
        </div>
    );
}

function SectionHeading({ title, description, actions }) {
    return (
        <div className="flex flex-col gap-2 border-b border-line px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 className="text-sm font-semibold text-ink">{title}</h2>
                {description ? <p className="mt-0.5 text-xs text-faint">{description}</p> : null}
            </div>
            {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
        </div>
    );
}

function JsonDetails({ value }) {
    if (!value || Object.keys(value).length === 0) {
        return <span className="text-xs text-faint">—</span>;
    }

    return (
        <details className="group">
            <summary className="cursor-pointer font-mono text-[10px] text-accent marker:content-none">
                <span className="group-open:hidden">view payload</span>
                <span className="hidden group-open:inline">hide payload</span>
            </summary>
            <pre className="mt-2 max-h-52 overflow-auto rounded-md border border-line bg-canvas p-2.5 font-mono text-[10px] leading-4 text-muted">
                {JSON.stringify(value, null, 2)}
            </pre>
        </details>
    );
}

export default function ServersShow({
    server,
    metrics,
    certificates,
    commands,
    activeEnrollment,
    hasActiveCertificate,
    operations,
    stateActions,
    can,
}) {
    const [pendingState, setPendingState] = useState(null);
    const [revokeOpen, setRevokeOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [stateBusy, setStateBusy] = useState(false);

    const enrollmentForm = useForm({});
    const commandForm = useForm({ operation: operations[0]?.value ?? 'server.inspect', reason: '' });
    const revokeForm = useForm({ reason: '' });

    const latest = metrics.length > 0 ? metrics[metrics.length - 1] : null;
    const flashProps = usePage().props.flash;

    const cpuSeries = metrics.map((metric) => Number(metric.cpu_usage ?? 0));
    const memorySeries = metrics.map((metric) => Number(metric.memory_usage ?? 0));
    const diskSeries = metrics.map((metric) => Number(metric.disk_usage ?? 0));

    function issueEnrollment() {
        enrollmentForm.post(`/servers/${server.id}/enrollment`, { preserveScroll: true });
    }

    function applyState() {
        if (!pendingState) {
            return;
        }

        setStateBusy(true);
        router.post(
            `/servers/${server.id}/state`,
            { status: pendingState, confirm: true },
            {
                preserveScroll: true,
                onFinish: () => {
                    setStateBusy(false);
                    setPendingState(null);
                },
            },
        );
    }

    function revokeCertificates() {
        revokeForm.post(`/servers/${server.id}/certificates/revoke`, {
            preserveScroll: true,
            onSuccess: () => setRevokeOpen(false),
        });
    }

    function issueCommand(event) {
        event.preventDefault();
        commandForm.post(`/servers/${server.id}/commands`, {
            preserveScroll: true,
            onSuccess: () => commandForm.reset('reason'),
        });
    }

    function cancelCommand(commandId) {
        router.post(`/servers/${server.id}/commands/${commandId}/cancel`, {}, { preserveScroll: true });
    }

    function deleteServer() {
        router.delete(`/servers/${server.id}`, {
            onFinish: () => setDeleteOpen(false),
        });
    }

    const selectedOperation = operations.find((operation) => operation.value === commandForm.data.operation);
    const commandAllowed = can.operate || can.deploy;

    return (
        <AppLayout
            title={server.name}
            description={server.hostname}
            eyebrow="Server"
            actions={
                <>
                    <Link href="/servers" className={buttonClass({ variant: 'ghost', size: 'md' })}>
                        <Icon name="arrowLeft" size={15} />
                        All servers
                    </Link>
                    {can.operate ? (
                        <div className="flex flex-wrap gap-1.5">
                            {stateActions.map((action) => (
                                <button
                                    key={action.value}
                                    type="button"
                                    onClick={() => setPendingState(action.value)}
                                    className={buttonClass({ variant: 'secondary', size: 'sm' })}
                                >
                                    {action.label}
                                </button>
                            ))}
                        </div>
                    ) : null}
                    {can.manageInfrastructure && !hasActiveCertificate ? (
                        <button
                            type="button"
                            onClick={issueEnrollment}
                            disabled={enrollmentForm.processing}
                            className={buttonClass({ variant: 'primary', size: 'md' })}
                        >
                            <Icon name="key" size={15} />
                            {enrollmentForm.processing ? 'Issuing…' : 'Issue enrollment token'}
                        </button>
                    ) : null}
                </>
            }
        >
            <Head title={server.name} />

            {flashProps.install_command ? (
                <div className="mb-5 rounded-xl border border-accent/30 bg-accent-soft p-4">
                    <div className="flex items-start gap-2.5">
                        <Icon name="terminal" size={17} className="mt-0.5 shrink-0 text-accent" />
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium text-accent">Installation command</p>
                            <p className="mt-0.5 text-xs text-muted">
                                Run this on the server as root. The token expires {formatRelative(flashProps.enrollment_expires_at)}.
                            </p>
                            <div className="mt-3">
                                <CopyField value={flashProps.install_command} />
                            </div>
                            {flashProps.enrollment_token ? (
                                <div className="mt-3">
                                    <CopyField label="Enrollment token (single use)" value={flashProps.enrollment_token} />
                                </div>
                            ) : null}
                        </div>
                    </div>
                </div>
            ) : null}

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
                <section className="cf-card">
                    <SectionHeading
                        title="Node"
                        description="Identity, placement and reported inventory."
                        actions={<StatusBadge tone={server.tone}>{server.status_label}</StatusBadge>}
                    />
                    <div className="p-4">
                        <div
                            className={`mb-4 rounded-lg border px-3 py-2.5 ${
                                server.status === 'unreachable' || server.status === 'error'
                                    ? 'border-danger/30 bg-danger/10'
                                    : 'border-line bg-surface-raised'
                            }`}
                        >
                            <p className="flex items-center gap-2 text-xs text-ink">
                                <Icon
                                    name={server.status === 'unreachable' || server.status === 'error' ? 'wifiOff' : 'wifi'}
                                    size={14}
                                    className={server.status === 'unreachable' || server.status === 'error' ? 'text-danger' : 'text-success'}
                                />
                                Last heartbeat {formatRelative(server.last_heartbeat_at)}
                                {server.last_heartbeat?.uptime_seconds ? (
                                    <span className="font-mono text-[10px] text-faint">· uptime {duration(server.last_heartbeat.uptime_seconds)}</span>
                                ) : null}
                            </p>
                        </div>

                        <div className="grid gap-x-6 sm:grid-cols-2">
                            <div>
                                <InfoRow label="Hostname" value={server.hostname} />
                                <InfoRow label="Origin" value={server.is_bootstrap ? 'Bootstrap node' : 'Manual registration'} mono={false} />
                                {server.is_bootstrap ? (
                                    <InfoRow label="Claimed" value={server.claimed_at ? formatDate(server.claimed_at) : 'Unclaimed'} mono={false} />
                                ) : null}
                                <InfoRow label="Public IP" value={server.public_ip} />
                                <InfoRow label="Private IP" value={server.private_ip} />
                                <InfoRow label="Region" value={server.region} />
                                <InfoRow label="OS" value={server.operating_system} />
                                <InfoRow label="Architecture" value={server.architecture} />
                                <InfoRow label="CPU cores" value={server.cpu_cores} />
                            </div>
                            <div>
                                <InfoRow label="Memory" value={formatBytes(server.total_memory_bytes)} />
                                <InfoRow label="Disk" value={formatBytes(server.total_disk_bytes)} />
                                <InfoRow label="Docker" value={server.docker_version} />
                                <InfoRow label="Agent" value={server.agent_version} />
                                <InfoRow label="Roles" value={server.roles.join(', ')} />
                                <InfoRow label="Registered" value={formatDate(server.created_at)} />
                                <InfoRow label="Inventory" value={formatRelative(server.last_inventory_at)} />
                            </div>
                        </div>

                        {server.capabilities.length > 0 ? (
                            <div className="mt-4 border-t border-line pt-3">
                                <p className="cf-label mb-2">Capabilities</p>
                                <div className="flex flex-wrap gap-1.5">
                                    {server.capabilities.map((capability) => (
                                        <span
                                            key={capability.name}
                                            className="rounded-md border border-line bg-surface-raised px-2 py-1 font-mono text-[10px] text-muted"
                                            title={JSON.stringify(capability.metadata ?? {})}
                                        >
                                            {capability.name}
                                            {capability.version ? <span className="text-faint"> · {capability.version}</span> : null}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        ) : null}

                        {server.inventory?.docker ? (
                            <div className="mt-4 border-t border-line pt-3">
                                <p className="cf-label mb-2">Docker daemon</p>
                                <div className="flex flex-wrap gap-x-5 gap-y-1.5 font-mono text-[11px] text-muted">
                                    <span>available: {server.inventory.docker.available ? 'yes' : 'no'}</span>
                                    <span>storage: {server.inventory.docker.storage_driver ?? '—'}</span>
                                    <span>rootless: {server.inventory.docker.rootless ? 'yes' : 'no'}</span>
                                </div>
                            </div>
                        ) : null}
                    </div>
                </section>

                <div className="space-y-5">
                    <section className="cf-card">
                        <SectionHeading title="Metrics" description="Host samples from the last 6 hours." />
                        <div className="space-y-3 p-4">
                            <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-1">
                                <MetricGauge
                                    label="CPU"
                                    icon="cpu"
                                    value={latest?.cpu_usage}
                                    detail={latest ? `sampled ${formatRelative(latest.recorded_at)}` : 'no samples'}
                                />
                                <MetricGauge
                                    label="Memory"
                                    icon="memory"
                                    value={latest?.memory_usage}
                                    detail={`${formatBytes(latest?.memory_used_bytes)} of ${formatBytes(server.total_memory_bytes)}`}
                                />
                                <MetricGauge
                                    label="Disk"
                                    icon="disk"
                                    value={latest?.disk_usage}
                                    detail={`${formatBytes(latest?.disk_used_bytes)} of ${formatBytes(server.total_disk_bytes)}`}
                                />
                            </div>

                            <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-1">
                                <div className="rounded-lg border border-line p-2.5">
                                    <p className="cf-label mb-1">CPU trend</p>
                                    <Sparkline values={cpuSeries} label="CPU trend over six hours" />
                                </div>
                                <div className="rounded-lg border border-line p-2.5">
                                    <p className="cf-label mb-1">Memory trend</p>
                                    <Sparkline values={memorySeries} tone="warning" label="Memory trend over six hours" />
                                </div>
                                <div className="rounded-lg border border-line p-2.5">
                                    <p className="cf-label mb-1">Disk trend</p>
                                    <Sparkline values={diskSeries} tone="warning" label="Disk trend over six hours" />
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-2">
                                <div className="rounded-lg border border-line px-3 py-2">
                                    <p className="cf-label">Net in</p>
                                    <p className="mt-1 font-mono text-xs text-ink">{formatBytes(latest?.network_in_bytes)}</p>
                                </div>
                                <div className="rounded-lg border border-line px-3 py-2">
                                    <p className="cf-label">Net out</p>
                                    <p className="mt-1 font-mono text-xs text-ink">{formatBytes(latest?.network_out_bytes)}</p>
                                </div>
                            </div>

                            {server.last_heartbeat?.load_average ? (
                                <p className="font-mono text-[10px] text-faint">
                                    load {server.last_heartbeat.load_average.map((value) => Number(value).toFixed(2)).join(' / ')}
                                </p>
                            ) : null}
                        </div>
                    </section>

                    <section className="cf-card">
                        <SectionHeading title="Enrollment" description="Single-use tokens and certificate state." />
                        <div className="space-y-3 p-4">
                            {activeEnrollment ? (
                                <div className="rounded-lg border border-warning/30 bg-warning/10 p-3">
                                    <p className="flex items-center gap-1.5 text-xs font-medium text-warning">
                                        <Icon name="clock" size={13} />
                                        Token awaiting use
                                    </p>
                                    <p className="mt-1 text-[11px] leading-5 text-muted">
                                        Expires {formatDate(activeEnrollment.expires_at)}. Issue a new token only if the
                                        previous one cannot be retrieved.
                                    </p>
                                    {can.manageInfrastructure && !hasActiveCertificate ? (
                                        <button
                                            type="button"
                                            onClick={issueEnrollment}
                                            disabled={enrollmentForm.processing}
                                            className={buttonClass({ variant: 'secondary', size: 'sm', className: 'mt-2' })}
                                        >
                                            <Icon name="refresh" size={13} />
                                            Reissue token
                                        </button>
                                    ) : null}
                                </div>
                            ) : null}

                            <p className="flex items-center gap-2 text-xs text-muted">
                                <Icon name={hasActiveCertificate ? 'shield' : 'alert'} size={14} className={hasActiveCertificate ? 'text-success' : 'text-warning'} />
                                {hasActiveCertificate
                                    ? 'An active certificate is installed. The agent can authenticate.'
                                    : 'No active certificate. Issue a token, then install the agent.'}
                            </p>

                            {can.manageInfrastructure ? (
                                <button
                                    type="button"
                                    onClick={() => setRevokeOpen(true)}
                                    className={buttonClass({ variant: 'danger', size: 'sm', className: 'w-full' })}
                                >
                                    <Icon name="ban" size={13} />
                                    Revoke certificates
                                </button>
                            ) : null}
                        </div>
                    </section>
                </div>
            </div>

            <section className="cf-card mt-5 overflow-hidden">
                <SectionHeading
                    title="Certificates"
                    description="Certificates issued to this node. Revocation is immediate."
                />
                {certificates.length === 0 ? (
                    <EmptyState icon="shield" title="No certificates" description="The agent has not enrolled yet." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[720px] text-left">
                            <thead>
                                <tr className="bg-surface-raised/50">
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Fingerprint</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Issued</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Expires</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">State</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Revocation</th>
                                </tr>
                            </thead>
                            <tbody>
                                {certificates.map((certificate) => (
                                    <tr key={certificate.id} className="cf-table-row">
                                        <td className="px-4 py-2.5">
                                            <code className="font-mono text-[11px] text-muted" title={certificate.fingerprint_sha256}>
                                                {certificate.fingerprint_sha256.slice(0, 24)}…
                                            </code>
                                        </td>
                                        <td className="px-4 py-2.5 text-xs text-muted">{formatDate(certificate.issued_at)}</td>
                                        <td className="px-4 py-2.5 text-xs text-muted">{formatDate(certificate.expires_at)}</td>
                                        <td className="px-4 py-2.5">
                                            <StatusBadge tone={certificate.is_active ? 'success' : certificate.revoked_at ? 'danger' : 'neutral'}>
                                                {certificate.is_active ? 'Active' : certificate.revoked_at ? 'Revoked' : 'Expired'}
                                            </StatusBadge>
                                        </td>
                                        <td className="px-4 py-2.5 text-xs text-muted">{certificate.revocation_reason ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            <section className="cf-card mt-5 overflow-hidden">
                <SectionHeading
                    title="Agent commands"
                    description="Allowlisted operations are delivered on the next poll. No shell access is exposed."
                />

                {commandAllowed ? (
                    <form onSubmit={issueCommand} className="grid gap-3 border-b border-line bg-surface-raised/30 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_auto]">
                        <Field label="Operation" htmlFor="operation" error={commandForm.errors.operation}>
                            <Select
                                id="operation"
                                value={commandForm.data.operation}
                                onChange={(event) => commandForm.setData('operation', event.target.value)}
                            >
                                {operations.map((operation) => (
                                    <option
                                        key={operation.value}
                                        value={operation.value}
                                        disabled={operation.requires_operator && !can.operate}
                                    >
                                        {operation.label}
                                        {operation.requires_operator && !can.operate ? ' (operator only)' : ''}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Reason" htmlFor="reason" error={commandForm.errors.reason} hint={selectedOperation?.description}>
                            <Input
                                id="reason"
                                value={commandForm.data.reason}
                                onChange={(event) => commandForm.setData('reason', event.target.value)}
                                placeholder="Optional context for the audit log"
                                error={commandForm.errors.reason}
                            />
                        </Field>
                        <div className="flex items-end">
                            <button type="submit" disabled={commandForm.processing} className={buttonClass({ variant: 'primary', className: 'w-full sm:w-auto' })}>
                                <Icon name="play" size={13} />
                                {commandForm.processing ? 'Queuing…' : 'Queue command'}
                            </button>
                        </div>
                    </form>
                ) : null}

                {commands.length === 0 ? (
                    <EmptyState
                        icon="terminal"
                        title="No commands issued"
                        description="Read-only inspection and health commands can be issued by developers; operational commands require an operator role."
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[820px] text-left">
                            <thead>
                                <tr className="bg-surface-raised/50">
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Operation</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Status</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Issued</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Attempts</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Result</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {commands.map((command) => {
                                    const terminal = ['completed', 'failed', 'expired', 'cancelled'].includes(command.status);

                                    return (
                                        <tr key={command.id} className="cf-table-row align-top">
                                            <td className="px-4 py-3">
                                                <p className="text-xs font-medium text-ink">{command.operation_label}</p>
                                                <p className="mt-0.5 font-mono text-[10px] text-faint">{command.id.slice(0, 8)}</p>
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge tone={commandStatusTone[command.status] ?? 'neutral'}>{command.status_label}</StatusBadge>
                                            </td>
                                            <td className="px-4 py-3 text-xs text-muted">{formatRelative(command.issued_at)}</td>
                                            <td className="px-4 py-3 font-mono text-xs text-muted tabular-nums">{command.attempts}</td>
                                            <td className="max-w-[280px] px-4 py-3">
                                                {command.error_message ? (
                                                    <p className="mb-1 text-xs text-danger">{command.error_message}</p>
                                                ) : null}
                                                <JsonDetails value={command.result} />
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                {can.operate && !terminal ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => cancelCommand(command.id)}
                                                        className={buttonClass({ variant: 'ghost', size: 'sm' })}
                                                    >
                                                        Cancel
                                                    </button>
                                                ) : null}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            {can.manageInfrastructure ? (
                <section className="mt-5 rounded-xl border border-danger/25 bg-danger/5 p-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="text-sm font-semibold text-ink">Remove server</h2>
                            <p className="mt-0.5 text-xs text-muted">
                                Revokes active certificates, then soft-deletes the server record. This does not touch the host.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => setDeleteOpen(true)}
                            className={buttonClass({ variant: 'danger', size: 'sm' })}
                        >
                            <Icon name="trash" size={13} />
                            Remove server
                        </button>
                    </div>
                </section>
            ) : null}

            <ConfirmDialog
                open={pendingState !== null}
                onClose={() => setPendingState(null)}
                onConfirm={applyState}
                title={`Change state to ${pendingState ?? ''}?`}
                description="The new state is recorded in the audit log. Draining and maintenance block scheduling but keep heartbeats flowing."
                confirmLabel="Apply state"
                tone="neutral"
                busy={stateBusy}
            />

            <ConfirmDialog
                open={revokeOpen}
                onClose={() => setRevokeOpen(false)}
                onConfirm={revokeCertificates}
                title="Revoke all certificates?"
                description="The agent loses access immediately and must be enrolled again with a new token."
                confirmLabel="Revoke certificates"
                busy={revokeForm.processing}
            >
                <Field label="Revocation reason" htmlFor="revoke-reason" error={revokeForm.errors.reason} required>
                    <Input
                        id="revoke-reason"
                        value={revokeForm.data.reason}
                        onChange={(event) => revokeForm.setData('reason', event.target.value)}
                        placeholder="Suspected compromise, decommission, rotation…"
                        error={revokeForm.errors.reason}
                    />
                </Field>
            </ConfirmDialog>

            <ConfirmDialog
                open={deleteOpen}
                onClose={() => setDeleteOpen(false)}
                onConfirm={deleteServer}
                title={`Remove ${server.name}?`}
                description="Active certificates are revoked and the record is soft-deleted. Audit history is retained."
                confirmLabel="Remove server"
            />
        </AppLayout>
    );
}
