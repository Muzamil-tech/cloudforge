import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { FlashMessages } from '../Components/FlashMessages';
import { Icon } from '../Components/Icons';
import { initials } from '../lib/format';

const navigation = [
    { label: 'Overview', href: '/', icon: 'grid', match: (path) => path === '/' },
    { label: 'Servers', href: '/servers', icon: 'server', match: (path) => path.startsWith('/servers') },
    { label: 'Projects', href: '/projects', icon: 'boxes', match: (path) => path.startsWith('/projects') },
    { label: 'Members', href: '/members', icon: 'users', match: (path) => path.startsWith('/members') },
    { label: 'API tokens', href: '/api-tokens', icon: 'key', match: (path) => path.startsWith('/api-tokens') },
    { label: 'Audit log', href: '/audit-log', icon: 'scroll', match: (path) => path.startsWith('/audit-log') },
    { label: 'Settings', href: '/settings', icon: 'settings', match: (path) => path.startsWith('/settings') },
];

export function BrandMark({ size = 30 }) {
    return (
        <span
            className="relative inline-flex items-center justify-center rounded-lg border border-accent/30 bg-accent-soft text-accent"
            style={{ width: size, height: size }}
            aria-hidden="true"
        >
            <svg viewBox="0 0 24 24" width={size * 0.62} height={size * 0.62} fill="none" stroke="currentColor" strokeWidth="1.5">
                <path d="m12 2.8 8.2 4.7v9.4L12 21.6l-8.2-4.7V7.5L12 2.8Z" opacity="0.5" />
                <path d="m3.8 7.5 8.2 4.7 8.2-4.7M12 12.2v9.4" />
            </svg>
        </span>
    );
}

function NavLinks({ path, onNavigate }) {
    return (
        <nav className="space-y-0.5" aria-label="Primary">
            {navigation.map((item) => {
                const active = item.match(path);

                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        onClick={onNavigate}
                        aria-current={active ? 'page' : undefined}
                        className={`group flex items-center gap-2.5 rounded-lg px-3 py-2 text-[13px] transition-colors ${
                            active
                                ? 'bg-accent-soft text-accent'
                                : 'text-muted hover:bg-surface-raised hover:text-ink'
                        }`}
                    >
                        <Icon name={item.icon} size={16} className={active ? 'text-accent' : 'text-faint group-hover:text-muted'} />
                        {item.label}
                        {active ? <span className="ml-auto h-1 w-1 rounded-full bg-accent" aria-hidden="true" /> : null}
                    </Link>
                );
            })}
        </nav>
    );
}

function SidebarContent({ path, onNavigate }) {
    const { auth, currentOrganization, organizations } = usePage().props;

    function switchOrganization(event) {
        const id = event.target.value;

        if (id && id !== currentOrganization?.id) {
            router.post(`/organizations/switch/${id}`, {}, { preserveScroll: false });
        }
    }

    return (
        <div className="flex h-full flex-col">
            <div className="px-4 pt-5 pb-4">
                <Link href="/" className="flex items-center gap-2.5" onClick={onNavigate}>
                    <BrandMark size={32} />
                    <span className="leading-tight">
                        <span className="block text-[15px] font-semibold tracking-tight text-ink">CloudForge</span>
                        <span className="block font-mono text-[10px] tracking-[0.18em] text-faint uppercase">Control plane</span>
                    </span>
                </Link>
            </div>

            <div className="px-3 pb-4">
                <div className="rounded-lg border border-line bg-surface-raised p-2.5">
                    <p className="cf-label mb-1.5">Organization</p>
                    {organizations.length > 1 ? (
                        <select
                            value={currentOrganization?.id ?? ''}
                            onChange={switchOrganization}
                            className="w-full cursor-pointer appearance-none bg-transparent text-[13px] font-medium text-ink focus-visible:outline-none"
                            aria-label="Switch organization"
                        >
                            {organizations.map((organization) => (
                                <option key={organization.id} value={organization.id} className="bg-surface text-ink">
                                    {organization.name}
                                </option>
                            ))}
                        </select>
                    ) : (
                        <p className="truncate text-[13px] font-medium text-ink">{currentOrganization?.name ?? 'No organization'}</p>
                    )}
                    <p className="mt-0.5 font-mono text-[10px] tracking-wide text-faint uppercase">
                        {currentOrganization?.role ?? '—'}
                    </p>
                </div>
            </div>

            <div className="flex-1 overflow-y-auto px-3">
                <NavLinks path={path} onNavigate={onNavigate} />
            </div>

            <div className="mt-auto border-t border-line p-3">
                <div className="flex items-center gap-2.5">
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-line-strong bg-surface-raised font-mono text-[11px] text-muted">
                        {initials(auth?.user?.name)}
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-[13px] font-medium text-ink">{auth?.user?.name}</p>
                        <p className="truncate text-[11px] text-faint">{auth?.user?.email}</p>
                    </div>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        className="rounded-md p-1.5 text-faint transition-colors hover:bg-surface-raised hover:text-danger"
                        aria-label="Sign out"
                    >
                        <Icon name="logout" size={16} />
                    </Link>
                </div>
            </div>
        </div>
    );
}

export function AppLayout({ children, title, description, actions, eyebrow }) {
    const page = usePage();
    const [mobileOpen, setMobileOpen] = useState(false);
    const path = page.url.split('?')[0];

    return (
        <div className="min-h-screen">
            <aside className="fixed inset-y-0 left-0 z-30 hidden w-60 border-r border-line bg-surface/80 backdrop-blur-sm lg:block">
                <SidebarContent path={path} />
            </aside>

            {mobileOpen ? (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="absolute inset-0 bg-black/70" onClick={() => setMobileOpen(false)} aria-hidden="true" />
                    <aside className="relative h-full w-64 border-r border-line bg-surface">
                        <SidebarContent path={path} onNavigate={() => setMobileOpen(false)} />
                    </aside>
                </div>
            ) : null}

            <div className="lg:pl-60">
                <header className="sticky top-0 z-20 border-b border-line bg-canvas/85 backdrop-blur-sm">
                    <div className="flex h-14 items-center gap-3 px-4 sm:px-6 lg:px-8">
                        <button
                            type="button"
                            onClick={() => setMobileOpen(true)}
                            className="rounded-md border border-line p-1.5 text-muted lg:hidden"
                            aria-label="Open navigation"
                        >
                            <Icon name="menu" size={18} />
                        </button>
                        <div className="min-w-0 flex-1">
                            <p className="truncate font-mono text-[10px] tracking-[0.16em] text-faint uppercase">
                                {page.props.currentOrganization?.name ?? 'CloudForge'}
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <Link
                                href="/servers/create"
                                className="hidden items-center gap-1.5 rounded-md border border-line px-2.5 py-1.5 text-xs text-muted transition-colors hover:border-accent/40 hover:text-accent sm:inline-flex"
                            >
                                <Icon name="plus" size={13} />
                                Add server
                            </Link>
                            <span className="flex h-7 w-7 items-center justify-center rounded-full border border-line-strong bg-surface-raised font-mono text-[10px] text-muted">
                                {initials(page.props.auth?.user?.name)}
                            </span>
                        </div>
                    </div>
                </header>

                <main className="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                    <FlashMessages flash={page.props.flash} />

                    {title ? (
                        <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <div className="min-w-0">
                                {eyebrow ? <p className="cf-label mb-1.5">{eyebrow}</p> : null}
                                <h1 className="text-2xl font-semibold tracking-tight text-ink">{title}</h1>
                                {description ? <p className="mt-1.5 max-w-2xl text-sm leading-6 text-muted">{description}</p> : null}
                            </div>
                            {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
                        </div>
                    ) : null}

                    {children}
                </main>
            </div>
        </div>
    );
}
