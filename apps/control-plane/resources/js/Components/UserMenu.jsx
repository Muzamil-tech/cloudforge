import { useEffect, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Icon } from './Icons';
import { initials } from '../lib/format';

export function UserMenu() {
    const { auth, currentOrganization } = usePage().props;
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        function onPointerDown(event) {
            if (ref.current && !ref.current.contains(event.target)) {
                setOpen(false);
            }
        }

        function onKeyDown(event) {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        }

        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                className="inline-flex items-center gap-1.5 rounded-lg border border-transparent p-0.5 transition-colors hover:border-line-strong"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label="Open account menu"
            >
                <span className="flex h-7 w-7 items-center justify-center rounded-full border border-line-strong bg-surface-raised font-mono text-[10px] text-muted">
                    {initials(auth?.user?.name)}
                </span>
                <Icon name="chevronDown" size={12} className="hidden text-faint sm:block" />
            </button>

            {open ? (
                <div
                    role="menu"
                    aria-label="Account"
                    className="absolute right-0 top-full z-50 mt-2 w-64 overflow-hidden rounded-xl border border-line bg-surface shadow-xl shadow-black/10"
                >
                    <div className="border-b border-line px-4 py-3">
                        <p className="truncate text-[13px] font-medium text-ink">{auth?.user?.name}</p>
                        <p className="mt-0.5 truncate text-[11px] text-faint">{auth?.user?.email}</p>
                        <p className="mt-2 font-mono text-[10px] tracking-wide text-faint uppercase">
                            {currentOrganization?.name ?? 'No organization'} · {currentOrganization?.role ?? '—'}
                        </p>
                    </div>
                    <Link
                        href="/settings"
                        role="menuitem"
                        onClick={() => setOpen(false)}
                        className="flex items-center gap-2 px-4 py-2.5 text-[13px] text-muted transition-colors hover:bg-surface-raised hover:text-ink"
                    >
                        <Icon name="settings" size={14} />
                        Organization settings
                    </Link>
                    <Link
                        href="/api-tokens"
                        role="menuitem"
                        onClick={() => setOpen(false)}
                        className="flex items-center gap-2 px-4 py-2.5 text-[13px] text-muted transition-colors hover:bg-surface-raised hover:text-ink"
                    >
                        <Icon name="key" size={14} />
                        API tokens
                    </Link>
                    <div className="cf-divider" />
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        role="menuitem"
                        className="flex w-full items-center gap-2 px-4 py-2.5 text-left text-[13px] text-muted transition-colors hover:bg-danger/10 hover:text-danger"
                    >
                        <Icon name="logout" size={14} />
                        Sign out
                    </Link>
                </div>
            ) : null}
        </div>
    );
}
