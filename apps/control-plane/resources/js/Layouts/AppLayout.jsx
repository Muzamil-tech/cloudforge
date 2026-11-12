import { useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Breadcrumbs } from '../Components/Breadcrumbs';
import { FlashMessages } from '../Components/FlashMessages';
import { Icon } from '../Components/Icons';
import { ThemeToggle } from '../Components/ThemeToggle';
import { UserMenu } from '../Components/UserMenu';
import { AppSidebar } from './AppSidebar';

export function AppLayout({ children, title, description, actions, eyebrow, renderHeader = true }) {
    const page = usePage();
    const [mobileOpen, setMobileOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(false);
    const path = page.url.split('?')[0];

    useEffect(() => {
        setCollapsed(window.localStorage.getItem('cf-sidebar') === 'collapsed');
    }, []);

    useEffect(() => {
        setMobileOpen(false);
    }, [path]);

    useEffect(() => {
        if (!mobileOpen) {
            return undefined;
        }

        function onKeyDown(event) {
            if (event.key === 'Escape') {
                setMobileOpen(false);
            }
        }

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [mobileOpen]);

    function toggleCollapsed() {
        setCollapsed((value) => {
            const next = !value;
            window.localStorage.setItem('cf-sidebar', next ? 'collapsed' : 'expanded');

            return next;
        });
    }

    return (
        <div className="min-h-screen">
            <aside
                className={`fixed inset-y-0 left-0 z-30 hidden border-r border-line bg-surface/80 backdrop-blur-sm transition-[width] duration-200 lg:block ${
                    collapsed ? 'w-16' : 'w-60'
                }`}
            >
                <AppSidebar path={path} collapsed={collapsed} onToggleCollapse={toggleCollapsed} />
            </aside>

            {mobileOpen ? (
                <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true" aria-label="Navigation">
                    <div className="absolute inset-0 bg-black/70" onClick={() => setMobileOpen(false)} aria-hidden="true" />
                    <aside className="relative h-full w-64 border-r border-line bg-surface">
                        <button
                            type="button"
                            onClick={() => setMobileOpen(false)}
                            className="absolute right-3 top-4 z-10 rounded-md p-1.5 text-faint transition-colors hover:bg-surface-raised hover:text-ink"
                            aria-label="Close navigation"
                        >
                            <Icon name="x" size={17} />
                        </button>
                        <AppSidebar
                            path={path}
                            mobile
                            onNavigate={() => setMobileOpen(false)}
                        />
                    </aside>
                </div>
            ) : null}

            <div className={`transition-[padding] duration-200 ${collapsed ? 'lg:pl-16' : 'lg:pl-60'}`}>
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
                            <Breadcrumbs path={path} title={title} />
                        </div>
                        <div className="flex items-center gap-2">
                            <Link
                                href="/servers/create"
                                className="hidden items-center gap-1.5 rounded-md border border-line px-2.5 py-1.5 text-xs text-muted transition-colors hover:border-accent/40 hover:text-accent sm:inline-flex"
                            >
                                <Icon name="plus" size={13} />
                                Add server
                            </Link>
                            <ThemeToggle />
                            <UserMenu />
                        </div>
                    </div>
                </header>

                <main className="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                    <FlashMessages flash={page.props.flash} />

                    {renderHeader && title ? (
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
