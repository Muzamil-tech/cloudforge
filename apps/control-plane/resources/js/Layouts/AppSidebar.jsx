import { Link, router, usePage } from '@inertiajs/react';
import { BrandMark } from '../Components/BrandMark';
import { Icon } from '../Components/Icons';
import { initials } from '../lib/format';

const groups = [
    {
        label: 'Workspace',
        items: [
            { label: 'Overview', href: '/', icon: 'grid', match: (path) => path === '/' },
            { label: 'Projects', href: '/projects', icon: 'boxes', match: (path) => path.startsWith('/projects') },
            { label: 'Databases', href: '/databases', icon: 'database', match: (path) => path.startsWith('/databases') },
        ],
    },
    {
        label: 'Infrastructure',
        items: [{ label: 'Servers', href: '/servers', icon: 'server', match: (path) => path.startsWith('/servers') }],
    },
    {
        label: 'Management',
        items: [
            { label: 'Team', href: '/members', icon: 'users', match: (path) => path.startsWith('/members') },
            { label: 'API tokens', href: '/api-tokens', icon: 'key', match: (path) => path.startsWith('/api-tokens') },
            { label: 'Audit log', href: '/audit-log', icon: 'scroll', match: (path) => path.startsWith('/audit-log') },
            { label: 'Settings', href: '/settings', icon: 'settings', match: (path) => path.startsWith('/settings') },
        ],
    },
];

function NavLinks({ path, collapsed, onNavigate }) {
    return (
        <div className="space-y-5">
            {groups.map((group) => (
                <div key={group.label}>
                    {!collapsed ? <p className="cf-label mb-1.5 px-3">{group.label}</p> : <div className="mx-3 mb-2 h-px bg-line" />}
                    <nav className="space-y-0.5" aria-label={group.label}>
                        {group.items.map((item) => {
                            const active = item.match(path);

                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    onClick={onNavigate}
                                    aria-current={active ? 'page' : undefined}
                                    title={collapsed ? item.label : undefined}
                                    className={`group flex items-center rounded-lg py-2 text-[13px] transition-colors ${
                                        collapsed ? 'justify-center px-2' : 'gap-2.5 px-3'
                                    } ${
                                        active
                                            ? 'bg-accent-soft text-accent'
                                            : 'text-muted hover:bg-surface-raised hover:text-ink'
                                    }`}
                                >
                                    <Icon
                                        name={item.icon}
                                        size={16}
                                        className={active ? 'text-accent' : 'text-faint group-hover:text-muted'}
                                    />
                                    {!collapsed ? <span className="truncate">{item.label}</span> : null}
                                    {!collapsed && active ? (
                                        <span className="ml-auto h-1 w-1 rounded-full bg-accent" aria-hidden="true" />
                                    ) : null}
                                </Link>
                            );
                        })}
                    </nav>
                </div>
            ))}
        </div>
    );
}

function OrganizationSwitcher({ collapsed }) {
    const { currentOrganization, organizations } = usePage().props;

    function switchOrganization(event) {
        const id = event.target.value;

        if (id && id !== currentOrganization?.id) {
            router.post(`/organizations/switch/${id}`, {}, { preserveScroll: false });
        }
    }

    if (collapsed) {
        return (
            <div className="px-2 pb-4">
                <div
                    className="mx-auto flex h-9 w-9 items-center justify-center rounded-lg border border-line bg-surface-raised font-mono text-[11px] text-muted"
                    title={currentOrganization?.name ?? 'No organization'}
                >
                    {initials(currentOrganization?.name)}
                </div>
            </div>
        );
    }

    return (
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
    );
}

export function AppSidebar({ path, collapsed = false, onToggleCollapse, onNavigate, mobile = false }) {
    return (
        <div className="flex h-full flex-col">
            <div className={`flex items-center pt-5 pb-4 ${collapsed ? 'justify-center px-2' : 'px-4'}`}>
                <Link href="/" className="flex items-center gap-2.5" onClick={onNavigate} aria-label="CloudForge overview">
                    <BrandMark size={32} />
                    {!collapsed ? (
                        <span className="leading-tight">
                            <span className="block text-[15px] font-semibold tracking-tight text-ink">CloudForge</span>
                            <span className="block font-mono text-[10px] tracking-[0.18em] text-faint uppercase">Control plane</span>
                        </span>
                    ) : null}
                </Link>
            </div>

            <OrganizationSwitcher collapsed={collapsed} />

            <div className="flex-1 overflow-y-auto px-3 pb-4">
                <NavLinks path={path} collapsed={collapsed} onNavigate={onNavigate} />
            </div>

            {!mobile ? (
                <div className="border-t border-line p-3">
                    <button
                        type="button"
                        onClick={onToggleCollapse}
                        className={`flex items-center rounded-lg py-2 text-[12px] text-faint transition-colors hover:bg-surface-raised hover:text-ink ${
                            collapsed ? 'mx-auto justify-center px-2' : 'w-full gap-2 px-3'
                        }`}
                        aria-label={collapsed ? 'Expand navigation' : 'Collapse navigation'}
                        title={collapsed ? 'Expand navigation' : 'Collapse navigation'}
                    >
                        <Icon name={collapsed ? 'chevronRight' : 'chevronLeft'} size={15} />
                        {!collapsed ? 'Collapse' : null}
                    </button>
                </div>
            ) : null}
        </div>
    );
}
