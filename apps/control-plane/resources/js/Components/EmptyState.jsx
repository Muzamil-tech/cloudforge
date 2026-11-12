import { Icon } from './Icons';

export function EmptyState({ icon = 'inbox', title, description, action = null, className = '' }) {
    return (
        <div className={`flex flex-col items-center justify-center px-6 py-12 text-center ${className}`}>
            <span className="mb-4 flex h-12 w-12 items-center justify-center rounded-xl border border-line bg-surface-raised text-faint">
                <Icon name={icon} size={22} />
            </span>
            <h3 className="text-sm font-semibold text-ink">{title}</h3>
            {description ? <p className="mt-1.5 max-w-md text-sm leading-6 text-muted">{description}</p> : null}
            {action ? <div className="mt-5">{action}</div> : null}
        </div>
    );
}
