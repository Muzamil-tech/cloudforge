import { Head, Link, useForm } from '@inertiajs/react';
import { AuthLayout } from '../../Layouts/AuthLayout';
import { Field, Input } from '../../Components/Field';
import { buttonClass } from '../../Components/Button';
import { Icon } from '../../Components/Icons';

export default function Login() {
    const form = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(event) {
        event.preventDefault();
        form.post('/login', {
            onFinish: () => form.reset('password'),
        });
    }

    return (
        <AuthLayout
            title="Sign in"
            description="Use your CloudForge account to open the control plane."
            footer={
                <p>
                    New here?{' '}
                    <Link href="/register" className="font-medium text-accent hover:text-accent-strong">
                        Create an account
                    </Link>{' '}
                    or ask an owner for an invitation.
                </p>
            }
        >
            <Head title="Sign in" />

            <form onSubmit={submit} className="space-y-4">
                <Field label="Email" htmlFor="email" error={form.errors.email} required>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        autoComplete="email"
                        autoFocus
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        placeholder="you@company.com"
                        error={form.errors.email}
                    />
                </Field>

                <Field label="Password" htmlFor="password" error={form.errors.password} required>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        autoComplete="current-password"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        placeholder="••••••••••••"
                        error={form.errors.password}
                    />
                </Field>

                <label className="flex cursor-pointer items-center gap-2 text-xs text-muted select-none">
                    <input
                        type="checkbox"
                        checked={form.data.remember}
                        onChange={(event) => form.setData('remember', event.target.checked)}
                        className="h-3.5 w-3.5 rounded border-line-strong bg-canvas accent-[var(--cf-accent)]"
                    />
                    Keep me signed in on this device
                </label>

                <button type="submit" disabled={form.processing} className={buttonClass({ variant: 'primary', size: 'lg', className: 'w-full' })}>
                    {form.processing ? 'Signing in…' : 'Sign in'}
                    {!form.processing ? <Icon name="chevronRight" size={15} /> : null}
                </button>
            </form>
        </AuthLayout>
    );
}
