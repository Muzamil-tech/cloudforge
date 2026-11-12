import { Head, useForm } from '@inertiajs/react';
import { AuthLayout } from '../Layouts/AuthLayout';
import { Field, Input } from '../Components/Field';
import { buttonClass } from '../Components/Button';
import { Icon } from '../Components/Icons';
import { StatusBadge } from '../Components/StatusBadge';
import { formatRelative } from '../lib/format';

export default function Onboarding({ bootstrapNodes = [] }) {
    const form = useForm({ name: '' });

    function submit(event) {
        event.preventDefault();
        form.post('/onboarding');
    }

    return (
        <AuthLayout
            title="Create your organization"
            description="Organizations isolate servers, projects, members and audit history. You can create more later."
        >
            <Head title="Create organization" />

            {bootstrapNodes.length > 0 ? (
                <div className="mb-5 rounded-lg border border-accent/30 bg-accent-soft p-3.5">
                    <p className="flex items-center gap-1.5 text-xs font-medium text-accent">
                        <Icon name="server" size={14} />
                        Local control-plane node detected
                    </p>
                    <p className="mt-1 text-xs leading-5 text-muted">
                        This host registered itself during installation. Creating the organization claims it
                        automatically — no manual server registration or enrollment token is needed.
                    </p>
                    <div className="mt-3 space-y-2">
                        {bootstrapNodes.map((node) => (
                            <div key={node.id} className="rounded-md border border-line bg-surface px-3 py-2">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="truncate text-xs font-medium text-ink">{node.name}</span>
                                    <StatusBadge tone={node.tone}>{node.status_label}</StatusBadge>
                                </div>
                                <p className="mt-0.5 truncate font-mono text-[10px] text-faint">
                                    {node.hostname}
                                    {node.machine_id ? ` · machine-id ${node.machine_id.slice(0, 12)}…` : ''}
                                </p>
                                <p className="mt-1 text-[11px] leading-5 text-muted">
                                    {node.agent_enrolled
                                        ? `Agent certificate installed. Last heartbeat ${formatRelative(node.last_heartbeat_at)}.`
                                        : node.enrollment_expires_at
                                          ? `Waiting for the agent to enroll. Its single-use token expires ${formatRelative(node.enrollment_expires_at)}.`
                                          : 'Waiting for the installer to issue an enrollment token.'}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
            ) : null}

            <form onSubmit={submit} className="space-y-4">
                <Field
                    label="Organization name"
                    htmlFor="name"
                    error={form.errors.name}
                    hint="A URL-safe slug is generated automatically."
                    required
                >
                    <Input
                        id="name"
                        name="name"
                        autoFocus
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        placeholder="Acme Infrastructure"
                        error={form.errors.name}
                    />
                </Field>

                <button type="submit" disabled={form.processing} className={buttonClass({ variant: 'primary', size: 'lg', className: 'w-full' })}>
                    {form.processing ? 'Creating workspace…' : 'Create workspace'}
                    {!form.processing ? <Icon name="chevronRight" size={15} /> : null}
                </button>
            </form>

            <div className="mt-6 rounded-lg border border-line bg-surface-raised p-3.5">
                <p className="cf-label mb-1.5">What happens next</p>
                {bootstrapNodes.length > 0 ? (
                    <ol className="space-y-1.5 text-xs leading-5 text-muted">
                        <li>1. Create the organization to claim this host as its first managed server.</li>
                        <li>2. Open Servers to watch heartbeats, inventory and metrics arrive.</li>
                        <li>3. Add more VPS nodes by registering them and issuing enrollment tokens.</li>
                    </ol>
                ) : (
                    <ol className="space-y-1.5 text-xs leading-5 text-muted">
                        <li>1. Register your first server and issue an enrollment token.</li>
                        <li>2. Install the agent with the generated command.</li>
                        <li>3. Watch heartbeats, inventory and metrics arrive.</li>
                    </ol>
                )}
            </div>
        </AuthLayout>
    );
}
