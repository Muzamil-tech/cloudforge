const toneClasses = {
    success: 'border-success/30 bg-success/10 text-success',
    info: 'border-accent/30 bg-accent-soft text-accent',
    warning: 'border-warning/30 bg-warning/10 text-warning',
    danger: 'border-danger/30 bg-danger/10 text-danger',
    neutral: 'border-line-strong bg-surface-raised text-muted',
};

export function StatusBadge({ tone = 'neutral', children, dot = true, className = '' }) {
    const classes = toneClasses[tone] ?? toneClasses.neutral;

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 font-mono text-[11px] tracking-wide uppercase ${classes} ${className}`}
        >
            {dot ? <span className="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" /> : null}
            {children}
        </span>
    );
}
