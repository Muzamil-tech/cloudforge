export function PageSection({ title, description, actions, children, className = '', bodyClassName = '' }) {
    return (
        <section className={`cf-card overflow-hidden ${className}`}>
            {title || actions ? (
                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-line px-4 py-3">
                    <div className="min-w-0">
                        <h2 className="text-sm font-semibold text-ink">{title}</h2>
                        {description ? <p className="mt-0.5 text-xs text-faint">{description}</p> : null}
                    </div>
                    {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
                </div>
            ) : null}
            <div className={bodyClassName}>{children}</div>
        </section>
    );
}
