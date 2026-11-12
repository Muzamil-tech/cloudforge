import { Head, Link, useForm } from '@inertiajs/react';
import { AuthLayout } from '../../Layouts/AuthLayout';
import { Field, Input } from '../../Components/Field';
import { buttonClass } from '../../Components/Button';
import { Icon } from '../../Components/Icons';

export default function Register({ invitation = null, invitationToken = null, invitationValid = false, bootstrap = false }) {
    const form = useForm({
        name: '',
        email: invitation?.email ?? '',
        password: '',
        password_confirmation: '',
        invitation: invitationToken ?? '',
    });

    function submit(event) {
        event.preventDefault();
        form.post('/register', {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    }

    const invitationEmailLocked = Boolean(invitation);

    return (
        <AuthLayout
            title={invitation ? `Join ${invitation.organization}` : bootstrap ? 'Create the owner account' : 'Create an account'}
            description={
                invitation
                    ? `You were invited as ${invitation.role}. Finish creating your account to accept.`
                    : bootstrap
                      ? 'This instance has no users yet. The first account becomes the platform owner.'
                      : 'Registration is invitation-only. Use the link from your organization owner.'
            }
            footer={
                <p>
                    Already have an account?{' '}
                    <Link href="/login" className="font-medium text-accent hover:text-accent-strong">
                        Sign in
                    </Link>
                </p>
            }
        >
            <Head title="Create account" />

            {invitationToken && !invitationValid ? (
                <div className="mb-4 flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2.5 text-xs leading-5 text-warning">
                    <Icon name="alert" size={15} className="mt-0.5 shrink-0" />
                    This invitation is expired or invalid. You can still create an account if registration is open.
                </div>
            ) : null}

            {invitation ? (
                <div className="mb-4 rounded-lg border border-accent/25 bg-accent-soft px-3 py-2.5">
                    <p className="cf-label mb-1">Invitation</p>
                    <p className="text-xs leading-5 text-ink">
                        {invitation.organization} · {invitation.role}
                    </p>
                </div>
            ) : null}

            <form onSubmit={submit} className="space-y-4">
                <Field label="Name" htmlFor="name" error={form.errors.name} required>
                    <Input
                        id="name"
                        name="name"
                        autoComplete="name"
                        autoFocus
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        placeholder="Ada Lovelace"
                        error={form.errors.name}
                    />
                </Field>

                <Field
                    label="Email"
                    htmlFor="email"
                    error={form.errors.email}
                    hint={invitationEmailLocked ? 'This invitation is bound to this address.' : undefined}
                    required
                >
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        autoComplete="email"
                        value={form.data.email}
                        readOnly={invitationEmailLocked}
                        onChange={(event) => form.setData('email', event.target.value)}
                        placeholder="you@company.com"
                        error={form.errors.email}
                        className={invitationEmailLocked ? 'opacity-70' : ''}
                    />
                </Field>

                <Field
                    label="Password"
                    htmlFor="password"
                    error={form.errors.password}
                    hint="At least 12 characters with upper and lower case letters and a number."
                    required
                >
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        autoComplete="new-password"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        placeholder="••••••••••••"
                        error={form.errors.password}
                    />
                </Field>

                <Field label="Confirm password" htmlFor="password_confirmation" error={form.errors.password_confirmation} required>
                    <Input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        autoComplete="new-password"
                        value={form.data.password_confirmation}
                        onChange={(event) => form.setData('password_confirmation', event.target.value)}
                        placeholder="••••••••••••"
                        error={form.errors.password_confirmation}
                    />
                </Field>

                {form.errors.invitation ? (
                    <p className="text-xs text-danger" role="alert">
                        {form.errors.invitation}
                    </p>
                ) : null}

                <button type="submit" disabled={form.processing} className={buttonClass({ variant: 'primary', size: 'lg', className: 'w-full' })}>
                    {form.processing ? 'Creating account…' : invitation ? 'Accept invitation' : 'Create account'}
                    {!form.processing ? <Icon name="chevronRight" size={15} /> : null}
                </button>
            </form>
        </AuthLayout>
    );
}
