import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AppLayout } from '../../Layouts/AppLayout';
import { StatusBadge } from '../../Components/StatusBadge';
import { ConfirmDialog } from '../../Components/ConfirmDialog';
import { CopyField } from '../../Components/CopyField';
import { Field, Input } from '../../Components/Field';
import { EmptyState } from '../../Components/EmptyState';
import { Icon } from '../../Components/Icons';
import { buttonClass } from '../../Components/Button';
import { formatDate, formatRelative } from '../../lib/format';

function AbilityToggle({ ability, checked, onChange }) {
    return (
        <label className="flex cursor-pointer items-start gap-2.5 rounded-lg border border-line bg-surface-raised px-3 py-2.5 transition-colors hover:border-line-strong">
            <input
                type="checkbox"
                checked={checked}
                onChange={onChange}
                className="mt-0.5 h-3.5 w-3.5 shrink-0 accent-[var(--cf-accent)]"
            />
            <span className="min-w-0">
                <span className="block font-mono text-[11px] text-ink">{ability.value}</span>
                <span className="mt-0.5 block text-[11px] leading-4 text-faint">{ability.description}</span>
            </span>
        </label>
    );
}

export default function ApiTokensIndex({ tokens, abilities, canManage }) {
    const [pendingRevoke, setPendingRevoke] = useState(null);
    const flash = usePage().props.flash;

    const form = useForm({
        name: '',
        abilities: ['servers:read'],
        expires_in_days: '',
    });

    function toggleAbility(value) {
        const selected = form.data.abilities.includes(value)
            ? form.data.abilities.filter((ability) => ability !== value)
            : [...form.data.abilities, value];

        form.setData('abilities', selected);
    }

    function submit(event) {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            expires_in_days: data.expires_in_days === '' ? null : Number(data.expires_in_days),
        })).post('/api-tokens', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    function revoke() {
        if (!pendingRevoke) {
            return;
        }

        router.delete(`/api-tokens/${pendingRevoke.id}`, {
            preserveScroll: true,
            onFinish: () => setPendingRevoke(null),
        });
    }

    return (
        <AppLayout
            title="API tokens"
            description="Organization-scoped bearer tokens for scripts, CI pipelines and integrations."
            eyebrow="Access"
        >
            <Head title="API tokens" />

            {flash.plaintext_token ? (
                <div className="mb-5 rounded-xl border border-accent/30 bg-accent-soft p-4">
                    <div className="flex items-start gap-2.5">
                        <Icon name="key" size={17} className="mt-0.5 shrink-0 text-accent" />
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium text-accent">Token created</p>
                            <p className="mt-0.5 text-xs text-muted">
                                Copy it now. Only a SHA-256 hash is stored, so the plaintext cannot be shown again.
                            </p>
                            <div className="mt-3">
                                <CopyField value={flash.plaintext_token} />
                            </div>
                        </div>
                    </div>
                </div>
            ) : null}

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                <div className="space-y-5">
                    <section className="cf-card overflow-hidden">
                        <div className="flex items-center justify-between border-b border-line px-4 py-3">
                            <div>
                                <h2 className="text-sm font-semibold text-ink">Active tokens</h2>
                                <p className="mt-0.5 text-xs text-faint">
                                    {tokens.length} token{tokens.length === 1 ? '' : 's'} in this organization
                                </p>
                            </div>
                        </div>

                        {tokens.length === 0 ? (
                            <EmptyState
                                icon="key"
                                title="No API tokens"
                                description="Create a scoped token to automate server management or read audit data."
                            />
                        ) : (
                            <ul className="divide-y divide-line">
                                {tokens.map((token) => (
                                    <li key={token.id} className="px-4 py-4">
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <p className="flex flex-wrap items-center gap-2 text-sm font-medium text-ink">
                                                    {token.name}
                                                    {token.expired ? <StatusBadge tone="danger">Expired</StatusBadge> : null}
                                                </p>
                                                <p className="mt-0.5 text-[11px] text-faint">
                                                    Created by {token.created_by} · {formatDate(token.created_at)} · last used{' '}
                                                    {formatRelative(token.last_used_at)}
                                                </p>
                                            </div>
                                            {canManage ? (
                                                <button
                                                    type="button"
                                                    onClick={() => setPendingRevoke(token)}
                                                    className={buttonClass({ variant: 'ghost', size: 'sm' })}
                                                >
                                                    <Icon name="ban" size={13} />
                                                    Revoke
                                                </button>
                                            ) : null}
                                        </div>

                                        <div className="mt-2.5 flex flex-wrap gap-1.5">
                                            {token.abilities.map((ability) => (
                                                <span
                                                    key={ability}
                                                    className="rounded-md border border-line bg-surface-raised px-1.5 py-0.5 font-mono text-[10px] text-muted"
                                                >
                                                    {ability}
                                                </span>
                                            ))}
                                        </div>

                                        <p className="mt-2 flex items-center gap-1.5 font-mono text-[10px] text-faint">
                                            <Icon name="clock" size={11} />
                                            {token.expires_at ? `expires ${formatDate(token.expires_at)}` : 'no expiry'}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <div className="rounded-xl border border-line bg-surface-raised p-4">
                        <p className="flex items-center gap-2 text-xs font-medium text-ink">
                            <Icon name="terminal" size={14} className="text-faint" />
                            Using a token
                        </p>
                        <pre className="cf-code mt-2 overflow-x-auto p-3 font-mono text-[11px] leading-5 text-muted">
                            {`curl -H "Authorization: Bearer cf_…" \\\n  -H "Accept: application/json" \\\n  ${typeof window !== 'undefined' ? window.location.origin : ''}/api/v1/servers`}
                        </pre>
                        <p className="mt-2 text-[11px] leading-5 text-faint">
                            Tokens are bound to this organization. A token from another tenant cannot read or mutate these
                            resources.
                        </p>
                    </div>
                </div>

                {canManage ? (
                    <aside>
                        <form onSubmit={submit} className="cf-card p-4">
                            <h2 className="text-sm font-semibold text-ink">New token</h2>
                            <p className="mt-0.5 text-xs text-faint">Grant only the abilities the integration needs.</p>

                            <div className="mt-4 space-y-3">
                                <Field label="Name" htmlFor="token-name" error={form.errors.name} required>
                                    <Input
                                        id="token-name"
                                        value={form.data.name}
                                        onChange={(event) => form.setData('name', event.target.value)}
                                        placeholder="CI deploy pipeline"
                                        autoComplete="off"
                                        error={form.errors.name}
                                    />
                                </Field>

                                <Field
                                    label="Abilities"
                                    htmlFor="token-abilities"
                                    error={form.errors.abilities ?? form.errors['abilities.0']}
                                    required
                                >
                                    <div id="token-abilities" className="space-y-1.5">
                                        {abilities.map((ability) => (
                                            <AbilityToggle
                                                key={ability.value}
                                                ability={ability}
                                                checked={form.data.abilities.includes(ability.value)}
                                                onChange={() => toggleAbility(ability.value)}
                                            />
                                        ))}
                                    </div>
                                </Field>

                                <Field
                                    label="Expires in"
                                    htmlFor="token-expiry"
                                    error={form.errors.expires_in_days}
                                    hint="Days from now. Leave blank for no expiry."
                                >
                                    <Input
                                        id="token-expiry"
                                        type="number"
                                        min="1"
                                        max="365"
                                        value={form.data.expires_in_days}
                                        onChange={(event) => form.setData('expires_in_days', event.target.value)}
                                        placeholder="90"
                                        error={form.errors.expires_in_days}
                                    />
                                </Field>

                                <button
                                    type="submit"
                                    disabled={form.processing || form.data.abilities.length === 0}
                                    className={buttonClass({ variant: 'primary', className: 'w-full' })}
                                >
                                    <Icon name="key" size={14} />
                                    {form.processing ? 'Creating…' : 'Create token'}
                                </button>
                            </div>
                        </form>
                    </aside>
                ) : null}
            </div>

            <ConfirmDialog
                open={pendingRevoke !== null}
                onClose={() => setPendingRevoke(null)}
                onConfirm={revoke}
                title={pendingRevoke ? `Revoke ${pendingRevoke.name}?` : 'Revoke token?'}
                description="Any integration using this token loses access immediately. This cannot be undone."
                confirmLabel="Revoke token"
            />
        </AppLayout>
    );
}
