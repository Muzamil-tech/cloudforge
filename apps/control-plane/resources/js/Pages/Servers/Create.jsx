import { Head, Link, useForm } from '@inertiajs/react';
import { AppLayout } from '../../Layouts/AppLayout';
import { Field, Input, Select } from '../../Components/Field';
import { buttonClass } from '../../Components/Button';
import { Icon } from '../../Components/Icons';

const roleHints = {
    control: 'Runs control-plane services',
    application: 'Hosts application workloads',
    database: 'Dedicated data services',
    build: 'Build and image pipelines',
    edge: 'Ingress and traffic edge',
    storage: 'Volumes and object storage',
    general: 'Unspecialized capacity',
};

export default function ServersCreate({ roles, regions }) {
    const form = useForm({
        name: '',
        hostname: '',
        public_ip: '',
        private_ip: '',
        region: '',
        roles: ['application'],
    });

    function toggleRole(value) {
        const selected = form.data.roles.includes(value)
            ? form.data.roles.filter((role) => role !== value)
            : [...form.data.roles, value];

        form.setData('roles', selected);
    }

    function submit(event) {
        event.preventDefault();
        form.post('/servers');
    }

    return (
        <AppLayout
            title="Register server"
            description="Create the control-plane record first. The agent installation command is generated on the server page."
            eyebrow="Infrastructure"
            actions={
                <Link href="/servers" className={buttonClass({ variant: 'secondary' })}>
                    <Icon name="arrowLeft" size={15} />
                    Back to servers
                </Link>
            }
        >
            <Head title="Register server" />

            <form onSubmit={submit} className="grid gap-5 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                <div className="cf-card p-5">
                    <div className="mb-4">
                        <h2 className="text-sm font-semibold text-ink">Identity</h2>
                        <p className="mt-0.5 text-xs text-faint">How this node appears in the control plane.</p>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Display name" htmlFor="name" error={form.errors.name} required>
                            <Input
                                id="name"
                                name="name"
                                autoFocus
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                placeholder="web-fsn1-01"
                                error={form.errors.name}
                            />
                        </Field>

                        <Field label="Hostname" htmlFor="hostname" error={form.errors.hostname} required>
                            <Input
                                id="hostname"
                                name="hostname"
                                value={form.data.hostname}
                                onChange={(event) => form.setData('hostname', event.target.value)}
                                placeholder="web-fsn1-01.example.com"
                                error={form.errors.hostname}
                            />
                        </Field>

                        <Field label="Public IP" htmlFor="public_ip" error={form.errors.public_ip} hint="Optional. IPv4 or IPv6.">
                            <Input
                                id="public_ip"
                                name="public_ip"
                                value={form.data.public_ip}
                                onChange={(event) => form.setData('public_ip', event.target.value)}
                                placeholder="203.0.113.10"
                                error={form.errors.public_ip}
                            />
                        </Field>

                        <Field label="Private IP" htmlFor="private_ip" error={form.errors.private_ip} hint="Optional. Used for internal networking.">
                            <Input
                                id="private_ip"
                                name="private_ip"
                                value={form.data.private_ip}
                                onChange={(event) => form.setData('private_ip', event.target.value)}
                                placeholder="10.0.0.10"
                                error={form.errors.private_ip}
                            />
                        </Field>

                        <Field label="Region" htmlFor="region" error={form.errors.region} hint="Informational label for placement.">
                            <Select
                                id="region"
                                name="region"
                                value={form.data.region}
                                onChange={(event) => form.setData('region', event.target.value)}
                            >
                                <option value="">No region</option>
                                {regions.map((region) => (
                                    <option key={region} value={region}>
                                        {region}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    </div>

                    <div className="mt-5 border-t border-line pt-5">
                        <h2 className="text-sm font-semibold text-ink">Roles</h2>
                        <p className="mt-0.5 text-xs text-faint">At least one. Roles guide future scheduling and placement.</p>

                        {form.errors.roles ? (
                            <p className="mt-2 text-xs text-danger" role="alert">
                                {form.errors.roles}
                            </p>
                        ) : null}

                        <div className="mt-3 grid gap-2 sm:grid-cols-2">
                            {roles.map((role) => {
                                const checked = form.data.roles.includes(role.value);

                                return (
                                    <label
                                        key={role.value}
                                        className={`flex cursor-pointer items-start gap-2.5 rounded-lg border p-3 transition-colors ${
                                            checked ? 'border-accent/40 bg-accent-soft' : 'border-line bg-canvas hover:border-line-strong'
                                        }`}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={checked}
                                            onChange={() => toggleRole(role.value)}
                                            className="mt-0.5 h-3.5 w-3.5 rounded border-line-strong bg-canvas accent-[var(--cf-accent)]"
                                        />
                                        <span>
                                            <span className={`block text-xs font-medium ${checked ? 'text-accent' : 'text-ink'}`}>
                                                {role.label}
                                            </span>
                                            <span className="mt-0.5 block text-[11px] leading-4 text-faint">{roleHints[role.value] ?? ''}</span>
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                    </div>

                    <div className="mt-5 flex items-center justify-end gap-2 border-t border-line pt-5">
                        <Link href="/servers" className={buttonClass({ variant: 'ghost' })}>
                            Cancel
                        </Link>
                        <button type="submit" disabled={form.processing} className={buttonClass({ variant: 'primary' })}>
                            {form.processing ? 'Registering…' : 'Register server'}
                            {!form.processing ? <Icon name="chevronRight" size={15} /> : null}
                        </button>
                    </div>
                </div>

                <aside className="space-y-4">
                    <div className="cf-card p-4">
                        <p className="cf-label mb-2">Enrollment flow</p>
                        <ol className="space-y-3">
                            {[
                                ['Register', 'Create the server record and roles.'],
                                ['Issue token', 'Generate a single-use token valid for a short window.'],
                                ['Install agent', 'Run the generated command on the VPS as root.'],
                                ['Enroll', 'The agent generates a key pair and exchanges the token for a certificate.'],
                            ].map(([title, description], index) => (
                                <li key={title} className="flex gap-2.5">
                                    <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-line bg-canvas font-mono text-[10px] text-faint">
                                        {index + 1}
                                    </span>
                                    <span>
                                        <span className="block text-xs font-medium text-ink">{title}</span>
                                        <span className="mt-0.5 block text-[11px] leading-4 text-faint">{description}</span>
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </div>

                    <div className="rounded-lg border border-accent/25 bg-accent-soft p-3.5">
                        <p className="flex items-center gap-1.5 text-xs font-medium text-accent">
                            <Icon name="lock" size={13} />
                            No shared secrets
                        </p>
                        <p className="mt-1.5 text-[11px] leading-5 text-muted">
                            Tokens are hashed at rest, expire quickly and can be used once. The agent keeps its private
                            key locally and never sends it to the control plane.
                        </p>
                    </div>
                </aside>
            </form>
        </AppLayout>
    );
}
