import { Link } from '@inertiajs/react';

const labels = {
    projects: 'Projects',
    databases: 'Databases',
    servers: 'Servers',
    members: 'Team',
    'api-tokens': 'API tokens',
    'audit-log': 'Audit log',
    settings: 'Settings',
    create: 'Add server',
};

function isUuid(value) {
    return /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);
}

export function Breadcrumbs({ path, title }) {
    const segments = path.split('/').filter(Boolean);
    const items = [{ label: 'Overview', href: '/' }];

    let href = '';

    segments.forEach((segment, index) => {
        href += `/${segment}`;
        const isLast = index === segments.length - 1;
        const label = isLast && title ? title : labels[segment] ?? (isUuid(segment) ? 'Details' : segment);

        items.push({ label, href: isLast ? null : href });
    });

    if (items.length === 1) {
        return null;
    }

    return (
        <nav aria-label="Breadcrumb" className="min-w-0">
            <ol className="flex min-w-0 items-center gap-1.5 overflow-hidden text-[12px]">
                {items.map((item, index) => (
                    <li key={`${item.label}-${index}`} className="flex min-w-0 items-center gap-1.5">
                        {index > 0 ? <span className="text-faint">/</span> : null}
                        {item.href ? (
                            <Link href={item.href} className="truncate text-faint transition-colors hover:text-accent">
                                {item.label}
                            </Link>
                        ) : (
                            <span className="truncate text-muted" aria-current="page">
                                {item.label}
                            </span>
                        )}
                    </li>
                ))}
            </ol>
        </nav>
    );
}
