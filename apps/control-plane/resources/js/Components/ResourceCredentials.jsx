import { CopyField } from './CopyField';
import { Icon } from './Icons';

export function ResourceCredentials({ credentials }) {
    if (!credentials) {
        return null;
    }

    return (
        <div className="rounded-xl border border-success/30 bg-success/10 p-4">
            <div className="flex items-start gap-2">
                <Icon name="key" size={16} className="mt-0.5 shrink-0 text-success" />
                <div>
                    <p className="text-sm font-semibold text-success">Credentials for {credentials.name}</p>
                    <p className="mt-1 text-xs leading-5 text-muted">
                        These credentials are shown once. Copy them now. They will not be shown again.
                    </p>
                </div>
            </div>
            <div className="mt-3 grid gap-3 lg:grid-cols-2">
                <CopyField label="Connection URL" value={credentials.url} />
                <CopyField label="Password" value={credentials.password} />
            </div>
        </div>
    );
}
