import { useState } from 'react';
import { Icon } from './Icons';

const tones = {
    success: {
        wrapper: 'border-success/30 bg-success/10 text-success',
        icon: 'check',
    },
    error: {
        wrapper: 'border-danger/30 bg-danger/10 text-danger',
        icon: 'alert',
    },
};

export function FlashMessages({ flash = {} }) {
    const [dismissed, setDismissed] = useState([]);
    const items = [
        { key: 'success', tone: 'success', message: flash.success },
        { key: 'error', tone: 'error', message: flash.error },
    ].filter((item) => item.message && !dismissed.includes(item.key));

    if (items.length === 0) {
        return null;
    }

    return (
        <div className="mb-5 space-y-2" aria-live="polite">
            {items.map((item) => {
                const tone = tones[item.tone];

                return (
                    <div
                        key={item.key}
                        className={`flex items-start gap-3 rounded-xl border px-4 py-3 text-sm ${tone.wrapper}`}
                        role="status"
                    >
                        <Icon name={tone.icon} size={17} className="mt-0.5 shrink-0" />
                        <p className="flex-1 leading-6">{item.message}</p>
                        <button
                            type="button"
                            onClick={() => setDismissed((current) => [...current, item.key])}
                            className="rounded p-1 opacity-70 transition-opacity hover:opacity-100"
                            aria-label="Dismiss notification"
                        >
                            <Icon name="x" size={15} />
                        </button>
                    </div>
                );
            })}
        </div>
    );
}
