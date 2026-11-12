import { useState } from 'react';
import { Icon } from './Icons';

function currentTheme() {
    if (typeof document === 'undefined') {
        return 'dark';
    }

    return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

export function ThemeToggle({ className = '' }) {
    const [theme, setTheme] = useState(() => currentTheme());

    function toggle() {
        const next = theme === 'dark' ? 'light' : 'dark';
        const root = document.documentElement;
        root.classList.toggle('dark', next === 'dark');

        try {
            localStorage.setItem('cf-theme', next);
        } catch {
            // The preference still applies for this page view.
        }

        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', next === 'dark' ? '#0b0f14' : '#f8fafc');
        setTheme(next);
    }

    return (
        <button
            type="button"
            onClick={toggle}
            className={`inline-flex h-8 w-8 items-center justify-center rounded-lg border border-line bg-surface-raised text-muted transition-colors hover:border-accent/40 hover:text-accent ${className}`}
            aria-label={theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}
            title={theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}
        >
            <Icon name={theme === 'dark' ? 'sun' : 'moon'} size={15} />
        </button>
    );
}
