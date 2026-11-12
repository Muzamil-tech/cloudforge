import { useEffect, useRef } from 'react';
import { Icon } from './Icons';
import { buttonClass } from './Button';

export function ConfirmDialog({
    open,
    onClose,
    onConfirm,
    title,
    description,
    confirmLabel = 'Confirm',
    tone = 'danger',
    busy = false,
    children = null,
}) {
    const dialogRef = useRef(null);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        function onKeyDown(event) {
            if (event.key === 'Escape') {
                onClose();
            }
        }

        document.addEventListener('keydown', onKeyDown);
        dialogRef.current?.querySelector('button')?.focus();

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [open, onClose]);

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div
                className="absolute inset-0 bg-black/70 backdrop-blur-sm"
                onClick={onClose}
                aria-hidden="true"
            />
            <div
                ref={dialogRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby="confirm-dialog-title"
                className="cf-card relative w-full max-w-md bg-surface-raised p-5 shadow-2xl"
            >
                <div className="flex items-start gap-3">
                    <span
                        className={`mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${
                            tone === 'danger' ? 'bg-danger/10 text-danger' : 'bg-accent-soft text-accent'
                        }`}
                    >
                        <Icon name={tone === 'danger' ? 'alert' : 'shield'} size={18} />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h2 id="confirm-dialog-title" className="text-sm font-semibold text-ink">
                            {title}
                        </h2>
                        <p className="mt-1.5 text-sm leading-6 text-muted">{description}</p>
                    </div>
                </div>
                {children ? <div className="mt-4">{children}</div> : null}
                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" className={buttonClass({ variant: 'secondary', size: 'sm' })} onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        className={buttonClass({ variant: tone === 'danger' ? 'danger' : 'primary', size: 'sm' })}
                        onClick={onConfirm}
                        disabled={busy}
                    >
                        {busy ? 'Working…' : confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}
