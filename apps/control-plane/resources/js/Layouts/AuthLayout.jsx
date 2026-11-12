import { Link, usePage } from '@inertiajs/react';
import { BrandMark } from './AppLayout';
import { FlashMessages } from '../Components/FlashMessages';

const highlights = [
    {
        icon: 'shield',
        title: 'Certificate-bound agents',
        description: 'Every node authenticates with a short-lived enrollment token and its own revocable certificate.',
    },
    {
        icon: 'activity',
        title: 'Continuous health signals',
        description: 'Heartbeats, inventory and metrics arrive through a signed agent protocol.',
    },
    {
        icon: 'lock',
        title: 'Isolated by default',
        description: 'Organizations, servers, projects and audit history stay inside their tenant boundary.',
    },
];

export function AuthLayout({ title, description, children, footer }) {
    const page = usePage();

    return (
        <div className="relative min-h-screen lg:grid lg:grid-cols-[1.05fr_1fr]">
            <div className="relative hidden flex-col justify-between overflow-hidden border-r border-line bg-surface/60 p-10 lg:flex xl:p-14">
                <div
                    className="pointer-events-none absolute inset-0 opacity-70"
                    style={{
                        background:
                            'radial-gradient(600px circle at 20% 20%, rgba(45,212,191,0.10), transparent 60%), radial-gradient(500px circle at 80% 80%, rgba(45,212,191,0.06), transparent 60%)',
                    }}
                    aria-hidden="true"
                />
                <div className="relative">
                    <Link href="/" className="inline-flex items-center gap-2.5">
                        <BrandMark size={36} />
                        <span className="leading-tight">
                            <span className="block text-base font-semibold tracking-tight text-ink">CloudForge</span>
                            <span className="block font-mono text-[10px] tracking-[0.18em] text-faint uppercase">Self-hosted PaaS</span>
                        </span>
                    </Link>

                    <div className="mt-16 max-w-md">
                        <p className="cf-label mb-4">Infrastructure, forged</p>
                        <h1 className="text-4xl leading-[1.1] font-semibold tracking-tight text-ink">
                            A control plane for the servers you already own.
                        </h1>
                        <p className="mt-4 text-sm leading-7 text-muted">
                            Register VPS nodes, enroll a hardened agent, and watch the fleet from one tenant-isolated
                            console. No exposed Docker sockets, no shared secrets, no arbitrary shell.
                        </p>
                    </div>
                </div>

                <div className="relative mt-12 space-y-4">
                    {highlights.map((highlight) => (
                        <div key={highlight.title} className="flex gap-3">
                            <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-line bg-surface-raised text-accent">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" aria-hidden="true">
                                    {highlight.icon === 'shield' ? (
                                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Zm-3-10 2 2 4-4" />
                                    ) : highlight.icon === 'activity' ? (
                                        <path d="M3 12h4l3-8 4 16 3-8h4" />
                                    ) : (
                                        <>
                                            <rect x="4" y="10" width="16" height="11" rx="2" />
                                            <path d="M8 10V7a4 4 0 0 1 8 0v3" />
                                        </>
                                    )}
                                </svg>
                            </span>
                            <div>
                                <p className="text-[13px] font-medium text-ink">{highlight.title}</p>
                                <p className="mt-0.5 text-xs leading-5 text-muted">{highlight.description}</p>
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            <div className="flex min-h-screen items-center justify-center px-5 py-10 sm:px-8">
                <div className="w-full max-w-sm">
                    <div className="mb-8 flex items-center gap-2.5 lg:hidden">
                        <BrandMark size={34} />
                        <span className="text-base font-semibold tracking-tight text-ink">CloudForge</span>
                    </div>

                    <FlashMessages flash={page.props.flash} />

                    <h2 className="text-xl font-semibold tracking-tight text-ink">{title}</h2>
                    {description ? <p className="mt-1.5 text-sm leading-6 text-muted">{description}</p> : null}

                    <div className="mt-7">{children}</div>

                    {footer ? <div className="mt-6 text-sm text-muted">{footer}</div> : null}

                    <p className="mt-10 border-t border-line pt-4 font-mono text-[10px] tracking-[0.14em] text-faint uppercase">
                        Phase 1 · Control plane v1
                    </p>
                </div>
            </div>
        </div>
    );
}
