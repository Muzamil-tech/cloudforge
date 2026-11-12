import { useState } from 'react';
import { Icon } from './Icons';

async function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(value);

        return true;
    }

    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    const copied = document.execCommand('copy');
    document.body.removeChild(textarea);

    return copied;
}

export function CopyField({ value, label, mono = true, multiline = false }) {
    const [copied, setCopied] = useState(false);
    const [failed, setFailed] = useState(false);

    async function handleCopy() {
        try {
            const result = await copyText(value);
            setCopied(result);
            setFailed(!result);
            window.setTimeout(() => {
                setCopied(false);
                setFailed(false);
            }, 2000);
        } catch {
            setFailed(true);
        }
    }

    return (
        <div className="space-y-1.5">
            {label ? <p className="cf-label">{label}</p> : null}
            <div className="cf-code flex items-start gap-2 p-1">
                <pre
                    className={`min-w-0 flex-1 overflow-x-auto px-2.5 py-2 text-[12px] leading-5 whitespace-pre-wrap break-all ${mono ? 'font-mono' : 'font-sans'} ${multiline ? '' : 'whitespace-pre-wrap'}`}
                >
                    {value}
                </pre>
                <button
                    type="button"
                    onClick={handleCopy}
                    className="mt-1 inline-flex shrink-0 items-center gap-1.5 rounded-md border border-line-strong bg-surface-raised px-2 py-1.5 text-[11px] text-muted transition-colors hover:text-ink"
                >
                    <Icon name={copied ? 'check' : 'copy'} size={13} />
                    {copied ? 'Copied' : failed ? 'Failed' : 'Copy'}
                </button>
            </div>
        </div>
    );
}
