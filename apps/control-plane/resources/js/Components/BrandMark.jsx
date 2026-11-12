export function BrandMark({ size = 30 }) {
    return (
        <span
            className="relative inline-flex items-center justify-center rounded-lg border border-accent/30 bg-accent-soft text-accent"
            style={{ width: size, height: size }}
            aria-hidden="true"
        >
            <svg
                viewBox="0 0 24 24"
                width={size * 0.62}
                height={size * 0.62}
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
            >
                <path d="m12 2.8 8.2 4.7v9.4L12 21.6l-8.2-4.7V7.5L12 2.8Z" opacity="0.5" />
                <path d="m3.8 7.5 8.2 4.7 8.2-4.7M12 12.2v9.4" />
            </svg>
        </span>
    );
}
