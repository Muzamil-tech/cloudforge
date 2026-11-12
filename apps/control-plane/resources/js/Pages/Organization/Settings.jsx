import { Head, Link, useForm } from '@inertiajs/react';
import { AppLayout } from '../../Layouts/AppLayout';
import { Field, Input } from '../../Components/Field';
import { Icon } from '../../Components/Icons';
import { buttonClass } from '../../Components/Button';
import { formatDate } from '../../lib/format';

function InfoRow({ label, value }) {
    return (
        <div className="flex items-center justify-between gap-3 border-t border-line py-2.5 first:border-t-0">
            <span className="font-mono text-[10px] tracking-[0.1em] text-faint uppercase">{label}</span>
            <span className="min-w-0 truncate text-right text-xs text-ink">{value ?? '—'}</span>
        </div>
    );
}

export default function OrganizationSettings({ organization, apiTokens }) {
    const form = useForm({ name: organization.name });

    function submit(event) {
        event.preventDefault();
        form.patch('/settings', { preserveScroll: true });
    }

    const dirty = form.data.name !== organization.name;

    return (
        <AppLayout
            title="Organization settings"
            description="Manage how this tenant is identified across the control plane."
            eyebrow="Organization"
        >
            <Head title="Settings" />

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                <div className="space-y-5">
                    <form onSubmit={submit} className="cf-card p-4">
                        <div className="flex items-center gap-2">
                            <Icon name="building" size={16} className="text-faint" />
                            <h2 className="text-sm font-semibold text-ink">General</h2>
                        </div>

                        <div className="mt-4 space-y-3">
                            <Field
                                label="Organization name"
                                htmlFor="organization-name"
                                error={form.errors.name}
                                hint="Shown in the sidebar and in audit metadata."
                                required
                            >
                                <Input
                                    id="organization-name"
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                    error={form.errors.name}
                                />
                            </Field>

                            <Field
                                label="Slug"
                                htmlFor="organization-slug"
                                hint="Immutable identifier used for storage and URL scoping."
                            >
                                <Input id="organization-slug" value={organization.slug} readOnly disabled className="font-mono" />
                            </Field>

                            <div className="flex items-center justify-end gap-2 pt-1">
                                {dirty ? (
                                    <button
                                        type="button"
                                        onClick={() => form.reset()}
                                        className={buttonClass({ variant: 'ghost', size: 'sm' })}
                                    >
                                        Discard
                                    </button>
                                ) : null}
                                <button
                                    type="submit"
                                    disabled={form.processing || !dirty}
                                    className={buttonClass({ variant: 'primary', size: 'md' })}
                                >
                                    {form.processing ? 'Saving…' : 'Save changes'}
                                </button>
                            </div>
                        </div>
                    </form>

                    <section className="cf-card p-4">
                        <div className="flex items-center justify-between gap-2">
                            <div>
                                <h2 className="text-sm font-semibold text-ink">API tokens</h2>
                                <p className="mt-0.5 text-xs text-faint">
                                    {organization.api_tokens_count} token{organization.api_tokens_count === 1 ? '' : 's'} bound to
                                    this organization.
                                </p>
                            </div>
                            <Link href="/api-tokens" className={buttonClass({ variant: 'secondary', size: 'sm' })}>
                                <Icon name="key" size={13} />
                                Manage
                            </Link>
                        </div>

                        {apiTokens.length > 0 ? (
                            <ul className="mt-3 divide-y divide-line border-t border-line">
                                {apiTokens.map((token) => (
                                    <li key={token.id} className="flex items-center justify-between gap-3 py-2">
                                        <span className="truncate text-xs text-muted">{token.name}</span>
                                        <span className="font-mono text-[10px] text-faint">{token.id.slice(0, 8)}</span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="mt-3 border-t border-line pt-3 text-xs text-faint">
                                No API tokens yet. Create one when you need programmatic access.
                            </p>
                        )}
                    </section>
                </div>

                <aside className="space-y-5">
                    <section className="cf-card p-4">
                        <h2 className="mb-2 text-sm font-semibold text-ink">Tenant</h2>
                        <InfoRow label="Plan" value={organization.plan} />
                        <InfoRow label="Members" value={organization.members_count} />
                        <InfoRow label="Created" value={formatDate(organization.created_at)} />
                        <InfoRow label="ID" value={organization.id} />
                    </section>

                    <div className="rounded-xl border border-line bg-surface-raised p-4">
                        <p className="flex items-center gap-2 text-xs font-medium text-ink">
                            <Icon name="shield" size={14} className="text-faint" />
                            Tenant isolation
                        </p>
                        <p className="mt-1.5 text-[11px] leading-5 text-faint">
                            Servers, projects, tokens and audit entries are scoped to this organization. Members of other
                            tenants cannot see or modify them, even with a valid session.
                        </p>
                    </div>
                </aside>
            </div>
        </AppLayout>
    );
}
