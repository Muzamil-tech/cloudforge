export const buttonBase =
    'inline-flex items-center justify-center gap-2 rounded-lg border text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50';

export const buttonVariants = {
    primary: 'border-accent/60 bg-accent text-[#04130f] hover:bg-accent-strong border-transparent',
    secondary: 'border-line-strong bg-surface-raised text-ink hover:border-accent/40 hover:text-accent',
    ghost: 'border-transparent bg-transparent text-muted hover:bg-surface-raised hover:text-ink',
    danger: 'border-danger/40 bg-danger/10 text-danger hover:bg-danger/20',
};

export const buttonSizes = {
    sm: 'h-8 px-2.5 text-xs',
    md: 'h-9 px-3.5',
    lg: 'h-10 px-4',
};

export function buttonClass({ variant = 'secondary', size = 'md', className = '' } = {}) {
    return `${buttonBase} ${buttonVariants[variant] ?? buttonVariants.secondary} ${buttonSizes[size] ?? buttonSizes.md} ${className}`;
}
