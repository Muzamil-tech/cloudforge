import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AppLayout } from '../../Layouts/AppLayout';
import { ConfirmDialog } from '../../Components/ConfirmDialog';
import { EmptyState } from '../../Components/EmptyState';
import { Icon } from '../../Components/Icons';
import { PageSection } from '../../Components/PageSection';
import { ProjectTabs } from '../../Components/ProjectTabs';
import { StatCard } from '../../Components/StatCard';
import { StatusBadge } from '../../Components/StatusBadge';
import { buttonClass } from '../../Components/Button';
import { formatDate } from '../../lib/format';

const pendingStatuses = ['deploying', 'starting', 'stopping', 'refreshing', 'deleting'];

const eventTones = {
    info: 'info',
    warning: 'warning',
    error: 'danger',
};

function LogBlock({ logs }) {
    const [copied, setCopied] = useState(false);

    async function copy() {
        if (!logs) {
            return;
        }

        try {
            await navigator.clipboard.writeText(logs);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1800);
        } catch {
            setCopied(false);
        }
    }

    return (
        <details className="mt-3 rounded-lg border border-line bg-surface">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2 text-xs font-medium text-muted">
                <span className="flex items-center gap-2">
                    <Icon name="terminal" size={13} />
                    Logs
                </span>
                <span className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={(event) => {
                            event.preventDefault();
                            event.stopPropagation();
                            copy();
                        }}
                        className="rounded-md px-2 py-1 text-[11px] text-faint hover:bg-surface-raised hover:text-ink"
                    >
                        {copied ? 'Copied' : 'Copy'}
                    </button>
                    <Icon name="chevronDown" size={13} />
                </span>
            </summary>
            <div className="border-t border-line">
                {logs ? (
                    <pre className="max-h-72 overflow-auto whitespace-pre-wrap break-words p-3 font-mono text-[11px] leading-5 text-muted">{logs}</pre>
                ) : (
                    <p className="p-3 text-xs text-faint">No logs were returned for this deployment.</p>
                )}
            </div>
        </details>
    );
}

function DeploymentCard({ deployment, onRollback }) {
    const events = deployment.events ?? [];

    return (
        <div className="rounded-xl border border-line bg-surface-raised/30 p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="font-mono text-xs text-ink">{deployment.image}</p>
                        <StatusBadge tone={deployment.status_tone}>{deployment.status_label}</StatusBadge>
                    </div>
                    <p className="mt-1 text-[11px] text-faint">
                        Requested {formatDate(deployment.started_at ?? deployment.created_at)}
                        {deployment.finished_at ? ` · finished ${formatDate(deployment.finished_at)}` : ''}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    {deployment.host_port ? <span className="font-mono text-[11px] text-faint">127.0.0.1:{deployment.host_port}</span> : null}
                    {deployment.can_rollback ? (
                        <button type="button" onClick={() => onRollback(deployment)} className={buttonClass({ variant: 'secondary', size: 'sm' })}>
                            <Icon name="refresh" size={12} />
                            Rollback
                        </button>
                    ) : null}
                </div>
            </div>

            {deployment.error_message ? (
                <p className="mt-3 rounded-lg border border-danger/30 bg-danger/10 px-3 py-2 text-xs leading-5 text-danger">{deployment.error_message}</p>
            ) : null}

            <LogBlock logs={deployment.logs} />

            {events.length > 0 ? (
                <ul className="mt-3 space-y-1.5">
                    {events.map((event) => (
                        <li key={event.id} className="flex items-start gap-2 text-[11px] leading-5">
                            <StatusBadge tone={eventTones[event.level] ?? 'neutral'} dot={false} className="mt-0.5 shrink-0">
                                {event.level}
                            </StatusBadge>
                            <span className="min-w-0 flex-1 text-muted">{event.message}</span>
                            <span className="shrink-0 text-faint">{formatDate(event.created_at)}</span>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}

export default function ApplicationShow({ project, application }) {
    const page = usePage();
    const flash = page.props.flash ?? {};
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [rollbackTarget, setRollbackTarget] = useState(null);

    const deployments = useMemo(() => application.deployments ?? [], [application.deployments]);
    const pending = pendingStatuses.includes(application.status)
        || deployments.some((deployment) => deployment.status === 'deploying');

    useEffect(() => {
        if (!pending) {
            return undefined;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['application'], preserveScroll: true });
        }, 3000);

        return () => window.clearInterval(timer);
    }, [pending]);

    function action(name) {
        router.post(`/projects/${project.id}/applications/${application.id}/${name}`, {}, { preserveScroll: true });
    }

    function remove() {
        router.delete(`/projects/${project.id}/applications/${application.id}`, {
            preserveScroll: true,
            onFinish: () => setDeleteOpen(false),
        });
    }

    function rollback() {
        if (!rollbackTarget) {
            return;
        }

        router.post(
            `/projects/${project.id}/applications/${application.id}/rollback/${rollbackTarget.id}`,
            {},
            { preserveScroll: true, onFinish: () => setRollbackTarget(null) },
        );
    }

    return (
        <AppLayout>
            <Head title={`${application.name} · ${project.name}`} />

            <div className="mx-auto max-w-6xl space-y-5">
                <div>
                    <Link href={`/projects/${project.id}/applications`} className="inline-flex items-center gap-1.5 text-xs font-medium text-faint hover:text-ink">
                        <Icon name="arrowLeft" size={13} />
                        Applications
                    </Link>

                    <div className="mt-3 flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-semibold tracking-[-0.03em] text-ink">{application.name}</h1>
                                <StatusBadge tone={application.status_tone}>{application.status_label}</StatusBadge>
                            </div>
                            <p className="mt-1 font-mono text-xs text-faint">
                                {application.image} · {application.environment.name} · {application.server.name}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {application.can_deploy ? (
                                <button type="button" onClick={() => action('deploy')} className={buttonClass({ variant: 'primary', size: 'sm' })}>
                                    <Icon name="refresh" size={13} />
                                    Deploy
                                </button>
                            ) : null}
                            {application.can_start ? (
                                <button type="button" onClick={() => action('start')} className={buttonClass({ variant: 'secondary', size: 'sm' })}>
                                    <Icon name="play" size={13} />
                                    Start
                                </button>
                            ) : null}
                            {application.can_stop ? (
                                <button type="button" onClick={() => action('stop')} className={buttonClass({ variant: 'secondary', size: 'sm' })}>
                                    <Icon name="ban" size={13} />
                                    Stop
                                </button>
                            ) : null}
                            {application.can_refresh ? (
                                <button type="button" onClick={() => action('refresh')} className={buttonClass({ variant: 'secondary', size: 'sm' })}>
                                    <Icon name="refresh" size={13} />
                                    Refresh
                                </button>
                            ) : null}
                            {application.can_delete ? (
                                <button type="button" onClick={() => setDeleteOpen(true)} className={buttonClass({ variant: 'danger', size: 'sm' })}>
                                    <Icon name="trash" size={13} />
                                    Delete
                                </button>
                            ) : null}
                        </div>
                    </div>

                    <ProjectTabs project={project} active="applications" />
                </div>

                {flash.success ? (
                    <div className="rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success" role="status">{flash.success}</div>
                ) : null}
                {flash.error ? (
                    <div className="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger" role="alert">{flash.error}</div>
                ) : null}

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <StatCard label="Application port" value={application.container_port} caption="inside the container" icon="server" tone="neutral" />
                    <StatCard label="Published port" value={application.host_port ?? '—'} caption="127.0.0.1 only" icon="wifi" tone={application.host_port ? 'success' : 'neutral'} />
                    <StatCard label="Deployments" value={deployments.length} caption="recorded attempts" icon="activity" tone="accent" />
                    <StatCard label="Health path" value={application.health_path ?? '—'} caption="HTTP readiness check" icon="shield" tone="neutral" />
                </div>

                {application.last_error ? (
                    <div className="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm leading-6 text-danger" role="alert">
                        <p className="font-medium">Latest application error</p>
                        <p className="mt-1">{application.last_error}</p>
                    </div>
                ) : null}

                <div className="grid gap-5 lg:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">
                    <PageSection
                        title="Deployments"
                        description="Each deployment is a real agent command with its own image, logs and result."
                    >
                        {deployments.length === 0 ? (
                            <EmptyState icon="activity" title="No deployments" description="This application has no deployment record yet." />
                        ) : (
                            <div className="space-y-3 p-4">
                                {deployments.map((deployment) => (
                                    <DeploymentCard
                                        key={deployment.id}
                                        deployment={deployment}
                                        onRollback={setRollbackTarget}
                                    />
                                ))}
                            </div>
                        )}
                    </PageSection>

                    <div className="space-y-5">
                        <PageSection title="Container" description="The current container reported by the agent.">
                            <dl className="divide-y divide-line text-sm">
                                <div className="flex items-start justify-between gap-4 px-4 py-2.5">
                                    <dt className="text-xs text-faint">Image</dt>
                                    <dd className="max-w-[65%] break-all text-right font-mono text-[11px] text-ink">{application.image}</dd>
                                </div>
                                <div className="flex items-start justify-between gap-4 px-4 py-2.5">
                                    <dt className="text-xs text-faint">Container</dt>
                                    <dd className="max-w-[65%] break-all text-right font-mono text-[11px] text-ink">{application.container_name}</dd>
                                </div>
                                <div className="flex items-start justify-between gap-4 px-4 py-2.5">
                                    <dt className="text-xs text-faint">Container ID</dt>
                                    <dd className="max-w-[65%] break-all text-right font-mono text-[11px] text-ink">{application.container_id ?? 'Pending agent result'}</dd>
                                </div>
                                <div className="flex items-start justify-between gap-4 px-4 py-2.5">
                                    <dt className="text-xs text-faint">Address</dt>
                                    <dd className="text-right font-mono text-[11px] text-ink">
                                        {application.host_port ? `${application.host}:${application.host_port}` : 'Not published yet'}
                                    </dd>
                                </div>
                                <div className="flex items-start justify-between gap-4 px-4 py-2.5">
                                    <dt className="text-xs text-faint">Health path</dt>
                                    <dd className="text-right font-mono text-[11px] text-ink">{application.health_path ?? 'None'}</dd>
                                </div>
                                <div className="flex items-start justify-between gap-4 px-4 py-2.5">
                                    <dt className="text-xs text-faint">Created</dt>
                                    <dd className="text-right text-xs text-muted">{formatDate(application.created_at)}</dd>
                                </div>
                            </dl>
                        </PageSection>

                        <PageSection title="How this works" description="No fake deployment state.">
                            <div className="space-y-3 p-4 text-xs leading-6 text-muted">
                                <p>The control plane records a deployment and issues a typed agent command.</p>
                                <p>The agent pulls the image, starts a new container, runs the health check and only then removes the previous container.</p>
                                <p>A failed deployment leaves the previous container running and records the real container logs on the failed deployment.</p>
                                <p className="text-faint">The published port is bound to 127.0.0.1. Use an SSH tunnel for access from outside the VPS until domains and the proxy are implemented.</p>
                            </div>
                        </PageSection>
                    </div>
                </div>
            </div>

            <ConfirmDialog
                open={deleteOpen}
                onClose={() => setDeleteOpen(false)}
                onConfirm={remove}
                title={`Delete ${application.name}?`}
                description="The agent removes every managed container for this application. Deployment history is kept in the audit record. This cannot be undone."
                confirmLabel="Delete application"
            />

            <ConfirmDialog
                open={rollbackTarget !== null}
                onClose={() => setRollbackTarget(null)}
                onConfirm={rollback}
                title="Roll back this application?"
                description={rollbackTarget
                    ? `A new deployment will be created from ${rollbackTarget.image}. The current container stays running until the new one is healthy.`
                    : ''}
                confirmLabel="Roll back"
                tone="accent"
            />
        </AppLayout>
    );
}
