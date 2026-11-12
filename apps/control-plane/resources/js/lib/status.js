export const serverStatusMeta = {
    installation_pending: { label: 'Installation pending', tone: 'info', group: 'Setup' },
    agent_registered: { label: 'Agent registered', tone: 'info', group: 'Setup' },
    health_checking: { label: 'Health checking', tone: 'info', group: 'Setup' },
    ready: { label: 'Ready', tone: 'success', group: 'Healthy' },
    active: { label: 'Active', tone: 'success', group: 'Healthy' },
    draining: { label: 'Draining', tone: 'warning', group: 'Locked' },
    maintenance: { label: 'Maintenance', tone: 'warning', group: 'Locked' },
    unreachable: { label: 'Unreachable', tone: 'danger', group: 'Attention' },
    error: { label: 'Error', tone: 'danger', group: 'Attention' },
};

export const commandStatusTone = {
    pending: 'info',
    acknowledged: 'info',
    executing: 'info',
    completed: 'success',
    failed: 'danger',
    expired: 'warning',
    cancelled: 'danger',
};

export const environmentTone = {
    production: 'danger',
    staging: 'warning',
    development: 'info',
};

export function environmentTypeLabel(value) {
    return value ? value.charAt(0).toUpperCase() + value.slice(1) : 'Environment';
}

export function abilityLabel(value) {
    return value.replace(':', ' · ').replace('read', 'read').replace('write', 'write');
}
