import { useId } from 'react';

function buildPath(values, width, height, padding) {
    if (values.length === 0) {
        return { line: '', area: '' };
    }

    const max = Math.max(...values, 1);
    const min = Math.min(...values, 0);
    const range = max - min || 1;
    const step = values.length === 1 ? 0 : (width - padding * 2) / (values.length - 1);

    const points = values.map((value, index) => {
        const x = padding + index * step;
        const y = height - padding - ((value - min) / range) * (height - padding * 2);

        return [x, y];
    });

    const line = points.map(([x, y], index) => `${index === 0 ? 'M' : 'L'}${x.toFixed(2)},${y.toFixed(2)}`).join(' ');
    const area = `${line} L${points[points.length - 1][0].toFixed(2)},${height - padding} L${points[0][0].toFixed(2)},${height - padding} Z`;

    return { line, area };
}

export function Sparkline({ values = [], tone = 'accent', height = 44, label }) {
    const gradientId = useId().replace(/:/g, '');
    const numeric = values
        .map((value) => Number(value))
        .filter((value) => Number.isFinite(value));
    const { line, area } = buildPath(numeric, 100, height, 4);
    const color = tone === 'danger' ? 'var(--cf-danger)' : tone === 'warning' ? 'var(--cf-warning)' : 'var(--cf-accent)';

    return (
        <div className="w-full" role="img" aria-label={label ?? 'Metric trend'}>
            <svg viewBox={`0 0 100 ${height}`} preserveAspectRatio="none" className="h-11 w-full overflow-visible">
                <defs>
                    <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor={color} stopOpacity="0.22" />
                        <stop offset="100%" stopColor={color} stopOpacity="0" />
                    </linearGradient>
                </defs>
                {numeric.length === 0 ? (
                    <line x1="0" y1={height / 2} x2="100" y2={height / 2} stroke="var(--cf-line-strong)" strokeDasharray="2 3" />
                ) : (
                    <>
                        <path d={area} fill={`url(#${gradientId})`} />
                        <path d={line} fill="none" stroke={color} strokeWidth="1.4" vectorEffect="non-scaling-stroke" />
                    </>
                )}
            </svg>
        </div>
    );
}
