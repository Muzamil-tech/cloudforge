import { Link } from '@inertiajs/react';
import { Icon } from './Icons';

function labelFor(link) {
    return link.label
        .replace('&laquo;', '')
        .replace('&raquo;', '')
        .replace('Previous', '')
        .replace('Next', '')
        .trim();
}

export function Pagination({ paginator, className = '' }) {
    if (!paginator || paginator.last_page <= 1) {
        return null;
    }

    const links = paginator.links ?? [];
    const previous = links[0];
    const next = links[links.length - 1];

    return (
        <nav
            className={`flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3 ${className}`}
            aria-label="Pagination"
        >
            <p className="font-mono text-[11px] tracking-wide text-faint uppercase">
                Page {paginator.current_page} of {paginator.last_page} · {paginator.total} records
            </p>
            <div className="flex items-center gap-1">
                {previous?.url ? (
                    <Link
                        href={previous.url}
                        preserveScroll
                        className="inline-flex h-8 items-center gap-1 rounded-md border border-line px-2.5 text-xs text-muted transition-colors hover:border-line-strong hover:text-ink"
                        aria-label="Previous page"
                    >
                        <Icon name="chevronLeft" size={14} />
                        Prev
                    </Link>
                ) : (
                    <span className="inline-flex h-8 items-center gap-1 rounded-md border border-line px-2.5 text-xs text-faint opacity-50">
                        <Icon name="chevronLeft" size={14} />
                        Prev
                    </span>
                )}

                {links.slice(1, -1).map((link, index) =>
                    link.url ? (
                        <Link
                            key={`${link.label}-${index}`}
                            href={link.url}
                            preserveScroll
                            aria-current={link.active ? 'page' : undefined}
                            className={`inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2 font-mono text-xs transition-colors ${
                                link.active
                                    ? 'border-accent/50 bg-accent-soft text-accent'
                                    : 'border-line text-muted hover:border-line-strong hover:text-ink'
                            }`}
                        >
                            {labelFor(link)}
                        </Link>
                    ) : (
                        <span key={`${link.label}-${index}`} className="px-1 text-xs text-faint">
                            …
                        </span>
                    ),
                )}

                {next?.url ? (
                    <Link
                        href={next.url}
                        preserveScroll
                        className="inline-flex h-8 items-center gap-1 rounded-md border border-line px-2.5 text-xs text-muted transition-colors hover:border-line-strong hover:text-ink"
                        aria-label="Next page"
                    >
                        Next
                        <Icon name="chevronRight" size={14} />
                    </Link>
                ) : (
                    <span className="inline-flex h-8 items-center gap-1 rounded-md border border-line px-2.5 text-xs text-faint opacity-50">
                        Next
                        <Icon name="chevronRight" size={14} />
                    </span>
                )}
            </div>
        </nav>
    );
}
