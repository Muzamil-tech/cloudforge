import { Link } from '@inertiajs/react';

export const projectTabs = [
    { key: 'overview', label: 'Overview', href: (id) => `/projects/${id}` },
    { key: 'applications', label: 'Applications', href: (id) => `/projects/${id}/applications` },
    { key: 'databases', label: 'Databases', href: (id) => `/projects/${id}/databases` },
    { key: 'environments', label: 'Environments', href: (id) => `/projects/${id}/environments` },
    { key: 'activity', label: 'Activity', href: (id) => `/projects/${id}/activity` },
    { key: 'settings', label: 'Settings', href: (id) => `/projects/${id}/settings` },
];

export function ProjectTabs({ project, active }) {
    return (
        <nav className="mt-5 flex gap-1 overflow-x-auto border-b border-line" aria-label="Project sections">
            {projectTabs.map((item) => {
                const isActive = item.key === active;

                return (
                    <Link
                        key={item.key}
                        href={item.href(project.id)}
                        className={`relative shrink-0 px-3 py-2.5 text-[13px] transition-colors ${
                            isActive ? 'text-ink' : 'text-faint hover:text-ink'
                        }`}
                        aria-current={isActive ? 'page' : undefined}
                    >
                        {item.label}
                        {isActive ? <span className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-accent" /> : null}
                    </Link>
                );
            })}
        </nav>
    );
}
