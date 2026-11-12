import { Icon } from './Icons';

export function StatCard({ label, value, caption, icon = 'activity', tone = 'neutral' }) {
    const toneClass = {
        accent: 'text-accent bg-accent-soft border-accent/20',
        success: 'text-success bg-success/10 border-success/20',
        warning: 'text-warning bg-warning/10 border-warning/20',
        danger: 'text-danger bg-danger/10 border-danger/20',
        info: 'text-info bg-info/10 border-info/20',
        neutral: 'text-faint bg-surface-raised border-line',
    }[tone] ?? 'text-faint bg-surface-raised border-line';

    return (
        <div className="cf-card p-4">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="cf-label">{label}</p>
                    <p className="mt-2 text-3xl font-semibold tracking-tight text-ink tabular-nums">{value}</p>
                </div>
                <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border ${toneClass}`}>
                    <Icon name={icon} size={16} />
                </span>
            </div>
            {caption ? <p className="mt-2 text-[11px] text-faint">{caption}</p> : null}
        </div>
    );
}
