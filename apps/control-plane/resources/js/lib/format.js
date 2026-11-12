export function formatDate(value) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

export function formatRelative(value) {
    if (!value) {
        return 'Never';
    }

    const date = new Date(value);
    const diff = Date.now() - date.getTime();
    const seconds = Math.round(diff / 1000);

    if (Number.isNaN(seconds)) {
        return 'Unknown';
    }

    if (Math.abs(seconds) < 45) {
        return 'Just now';
    }

    const units = [
        ['year', 31536000],
        ['month', 2592000],
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(-Math.round(seconds / size), unit);
        }
    }

    return formatter.format(-seconds, 'second');
}

export function formatBytes(value, fractions = 1) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '—';
    }

    const bytes = Number(value);

    if (bytes === 0) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    const exponent = Math.min(Math.floor(Math.log(Math.abs(bytes)) / Math.log(1024)), units.length - 1);
    const amount = bytes / 1024 ** exponent;

    return `${amount.toFixed(amount >= 100 || exponent === 0 ? 0 : fractions)} ${units[exponent]}`;
}

export function formatPercent(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '—';
    }

    return `${Number(value).toFixed(1)}%`;
}

export function formatNumber(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '—';
    }

    return new Intl.NumberFormat().format(Number(value));
}

export function initials(name) {
    if (!name) {
        return '?';
    }

    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

export function truncate(value, length = 24) {
    if (!value) {
        return '—';
    }

    return value.length > length ? `${value.slice(0, length)}…` : value;
}

export function duration(seconds) {
    if (seconds === null || seconds === undefined) {
        return '—';
    }

    const total = Math.max(0, Math.round(Number(seconds)));
    const days = Math.floor(total / 86400);
    const hours = Math.floor((total % 86400) / 3600);
    const minutes = Math.floor((total % 3600) / 60);

    if (days > 0) {
        return `${days}d ${hours}h`;
    }

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }

    return `${minutes}m`;
}

export function percent(value) {
    const number = Number(value);

    return Number.isFinite(number) ? Math.min(100, Math.max(0, number)) : 0;
}
