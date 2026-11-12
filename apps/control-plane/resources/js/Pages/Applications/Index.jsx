import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AppLayout } from '../../Layouts/AppLayout';
import { EmptyState } from '../../Components/EmptyState';
import { Field, Input, Select } from '../../Components/Field';
import { Icon } from '../../Components/Icons';
import { PageSection } from '../../Components/PageSection';
import { ProjectTabs } from '../../Components/ProjectTabs';
import { StatCard } from '../../Components/StatCard';
import { StatusBadge } from '../../Components/StatusBadge';
import { buttonClass } from '../../Components/Button';
import { formatDate } from '../../lib/format';

const pendingStatuses = ['deploying', 'starting', 'stopping', 'refreshing', 'deleting'];

export default function ApplicationsIndex({ project, applications = [], servers = [], canCreate = false }) {
    const page = usePage();
    const flash = page.props.flash ?? {};
    const [showForm, setShowForm] = useState(applications.length === 0 && canCreate);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');

    const form = useForm({
        name: '',
        image: 'nginx:1.27-alpine',
        container_port: 80,
        health_path: '/',
        environment_id: project.environments[0]?.id ?? '',
        server_id: servers[0]?.id ?? '',
    });

    const pending = applications.some((application) => pendingStatuses.includes(application.status));

    useEffect(() => {
        if (!pending) {
            return undefined;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['applications'], preserveScroll: true });
        }, 3000);

        return () => window.clearInterval(timer);
    }, [pending]);

    const counts = useMemo(() => ({
        total: applications.length,
        active: applications.filter((application) => application.status === 'active').length,
        failed: applications.filter((application) => application.status === 'failed').length,
        deploying: applications.filter((application) => pendingStatuses.includes(application.status)).length,
    }), [applications]);

    const visible = useMemo(() => {
        const term = search.trim().toLowerCase();

        return applications.filter((application) => {
            if (status && application.status !== status) {
                return false;
            }

            if (!term) {
                return true;
            }

            return [application.name, application.image, application.environment.name, application.server.name]
                .join(' ')
                .toLowerCase()
                .includes(term);
        });
    }, [applications, search, status]);

    function submit(event) {
        event.preventDefault();

        form.post(`/projects/${project.id}/applications`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('name');
                setShowForm(false);
            },
        });
    }

    return (
        <AppLayout>
            <Head title={`Applications · ${project.name}`} />

            <div className="mx-auto max-w-6xl space-y-5">
                <div>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                            <p className="font-mono text-[11px] uppercase tracking-[0.16em] text-faint">Project</p>
                            <div className="mt-1 flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-semibold tracking-[-0.03em] text-ink">{project.name}</h1>
                                <StatusBadge tone="neutral" dot={false}>Applications</StatusBadge>
                            </div>
                            <p className="mt-1 max-w-2xl text-sm leading-6 text-muted">
                                Deploy prebuilt container images to this project&apos;s environments. Every status comes from a real agent result.
                            </p>
                        </div>
                        {canCreate ? (
                            <button type="button" onClick={() => setShowForm((open) => !open)} className={buttonClass({ variant: 'primary' })}>
                                <Icon name={showForm ? 'x' : 'plus'} size={15} />
                                {showForm ? 'Close form' : 'Deploy application'}
                            </button>
                        ) : null}
                    </div>

                    <ProjectTabs project={project} active="applications" />
                </div>

                {flash.success ? (
                    <div className="rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success" role="status">
                        {flash.success}
                    </div>
                ) : null}
                {flash.error ? (
                    <div className="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger" role="alert">
                        {flash.error}
                    </div>
                ) : null}

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <StatCard label="Applications" value={counts.total} caption="in this project" icon="activity" tone="accent" />
                    <StatCard label="Active" value={counts.active} caption="healthy containers" icon="play" tone="success" />
                    <StatCard label="Deploying" value={counts.deploying} caption="waiting for the agent" icon="refresh" tone="info" />
                    <StatCard label="Failed" value={counts.failed} caption="need attention" icon="alert" tone={counts.failed > 0 ? 'warning' : 'success'} />
                </div>

                {showForm ? (
                    <PageSection
                        title="Deploy a prebuilt image"
                        description="The first deployment starts immediately. The image is pulled by the agent on the selected server."
                    >
                        <form onSubmit={submit} className="grid gap-4 p-4 sm:grid-cols-2">
                            <Field label="Application name" htmlFor="name" error={form.errors.name} required>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                    placeholder="web"
                                    autoComplete="off"
                                />
                            </Field>
                            <Field label="Container image" htmlFor="image" error={form.errors.image} hint="Example: nginx:1.27-alpine" required>
                                <Input
                                    id="image"
                                    value={form.data.image}
                                    onChange={(event) => form.setData('image', event.target.value)}
                                    placeholder="nginx:1.27-alpine"
                                    className="font-mono text-xs"
                                />
                            </Field>
                            <Field label="Container port" htmlFor="container_port" error={form.errors.container_port} hint="The port your application listens on inside the container." required>
                                <Input
                                    id="container_port"
                                    type="number"
                                    min="1"
                                    max="65535"
                                    value={form.data.container_port}
                                    onChange={(event) => form.setData('container_port', event.target.value)}
                                />
                            </Field>
                            <Field label="Health path" htmlFor="health_path" error={form.errors.health_path} hint="Optional HTTP path used to verify the container. Example: /up">
                                <Input
                                    id="health_path"
                                    value={form.data.health_path}
                                    onChange={(event) => form.setData('health_path', event.target.value)}
                                    placeholder="/up"
                                    className="font-mono text-xs"
                                />
                            </Field>
                            <Field label="Environment" htmlFor="environment_id" error={form.errors.environment_id} required>
                                <Select
                                    id="environment_id"
                                    value={form.data.environment_id}
                                    onChange={(event) => form.setData('environment_id', event.target.value)}
                                >
                                    {project.environments.map((environment) => (
                                        <option key={environment.id} value={environment.id}>{environment.name}</option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label="Server" htmlFor="server_id" error={form.errors.server_id} required>
                                <Select
                                    id="server_id"
                                    value={form.data.server_id}
                                    onChange={(event) => form.setData('server_id', event.target.value)}
                                >
                                    {servers.length === 0 ? <option value="">No ready servers</option> : null}
                                    {servers.map((server) => (
                                        <option key={server.id} value={server.id}>{server.name} · {server.docker_version ?? server.status_label}</option>
                                    ))}
                                </Select>
                            </Field>
                            <div className="flex items-center justify-end gap-2 sm:col-span-2">
                                <button type="button" onClick={() => setShowForm(false)} className={buttonClass({ variant: 'ghost' })}>
                                    Cancel
                                </button>
                                <button type="submit" disabled={form.processing || servers.length === 0} className={buttonClass({ variant: 'primary' })}>
                                    <Icon name="play" size={15} />
                                    {form.processing ? 'Deploying…' : 'Deploy'}
                                </button>
                            </div>
                        </form>
                    </PageSection>
                ) : null}

                <PageSection
                    title="Applications"
                    description="Real container deployments, image versions and latest deployment state."
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="relative">
                                <span className="sr-only">Search applications</span>
                                <Icon name="search" size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-faint" />
                                <input
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder="Search applications"
                                    className="cf-input h-8 w-52 pl-8 text-xs"
                                />
                            </div>
                            <select value={status} onChange={(event) => setStatus(event.target.value)} className="cf-input h-8 w-36 py-0 text-xs" aria-label="Filter by status">
                                <option value="">All statuses</option>
                                <option value="deploying">Deploying</option>
                                <option value="active">Active</option>
                                <option value="starting">Starting</option>
                                <option value="stopping">Stopping</option>
                                <option value="refreshing">Refreshing</option>
                                <option value="stopped">Stopped</option>
                                <option value="failed">Failed</option>
                                <option value="deleting">Deleting</option>
                            </select>
                        </div>
                    }
                >
                    {visible.length === 0 ? (
                        <EmptyState
                            icon="activity"
                            title={applications.length === 0 ? 'No applications yet' : 'No matching applications'}
                            description={applications.length === 0
                                ? 'Deploy a prebuilt container image to make this project run something real.'
                                : 'Change the search or status filter to see the other applications.'}
                            action={applications.length === 0 && canCreate ? (
                                <button type="button" onClick={() => setShowForm(true)} className={buttonClass({ variant: 'primary', size: 'sm' })}>
                                    <Icon name="plus" size={14} />
                                    Deploy application
                                </button>
                            ) : null}
                        />
                    ) : (
                        <ul className="divide-y divide-line">
                            {visible.map((application) => (
                                <li key={application.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-line bg-surface-raised text-faint">
                                        <Icon name="activity" size={16} />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Link
                                                href={`/projects/${project.id}/applications/${application.id}`}
                                                className="text-[13px] font-medium text-ink hover:text-accent"
                                            >
                                                {application.name}
                                            </Link>
                                            <StatusBadge tone={application.status_tone}>{application.status_label}</StatusBadge>
                                            {application.latest_deployment ? (
                                                <StatusBadge tone={application.latest_deployment.status_tone} dot={false}>
                                                    deployment: {application.latest_deployment.status_label}
                                                </StatusBadge>
                                            ) : null}
                                        </div>
                                        <p className="mt-0.5 font-mono text-[10px] text-faint">
                                            {application.image} · {application.environment.name} · {application.server.name}
                                            {application.host_port ? ` · 127.0.0.1:${application.host_port}` : ''}
                                        </p>
                                        {application.last_error ? (
                                            <p className="mt-1 line-clamp-2 text-[11px] text-danger">{application.last_error}</p>
                                        ) : null}
                                    </div>
                                    <p className="hidden text-xs text-faint sm:block">{formatDate(application.created_at)}</p>
                                    <Link
                                        href={`/projects/${project.id}/applications/${application.id}`}
                                        className={buttonClass({ variant: 'secondary', size: 'sm' })}
                                    >
                                        <Icon name="chevronRight" size={13} />
                                        Open
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </PageSection>
            </div>
        </AppLayout>
    );
}
