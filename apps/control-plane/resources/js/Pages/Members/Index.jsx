import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AppLayout } from '../../Layouts/AppLayout';
import { StatusBadge } from '../../Components/StatusBadge';
import { ConfirmDialog } from '../../Components/ConfirmDialog';
import { CopyField } from '../../Components/CopyField';
import { Field, Input, Select } from '../../Components/Field';
import { EmptyState } from '../../Components/EmptyState';
import { Icon } from '../../Components/Icons';
import { buttonClass } from '../../Components/Button';
import { formatDate, formatRelative, initials } from '../../lib/format';

function MemberRow({ member, assignableRoles, canManage, onRemove }) {
    const form = useForm({ role: member.role });

    function submit(event) {
        event.preventDefault();
        form.patch(`/members/${member.id}`, { preserveScroll: true });
    }

    const editable = canManage && !member.is_owner && !member.is_self;
    const changed = form.data.role !== member.role;

    return (
        <tr className="cf-table-row align-middle">
            <td className="px-4 py-3">
                <div className="flex items-center gap-2.5">
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-line-strong bg-surface-raised font-mono text-[10px] text-muted">
                        {initials(member.name)}
                    </span>
                    <div className="min-w-0">
                        <p className="flex items-center gap-1.5 text-xs font-medium text-ink">
                            {member.name}
                            {member.is_self ? <span className="text-faint">(you)</span> : null}
                        </p>
                        <p className="truncate text-[11px] text-faint">{member.email}</p>
                    </div>
                </div>
            </td>
            <td className="px-4 py-3">
                {editable ? (
                    <form onSubmit={submit} className="flex items-center gap-1.5">
                        <Select
                            value={form.data.role}
                            onChange={(event) => form.setData('role', event.target.value)}
                            className="h-8 w-32 py-0 text-xs"
                            aria-label={`Role for ${member.name}`}
                        >
                            <option value={member.role}>{member.role_label}</option>
                            {assignableRoles
                                .filter((assignable) => assignable.value !== member.role)
                                .map((assignable) => (
                                    <option key={assignable.value} value={assignable.value}>
                                        {assignable.label}
                                    </option>
                                ))}
                        </Select>
                        {changed ? (
                            <button
                                type="submit"
                                disabled={form.processing}
                                className={buttonClass({ variant: 'primary', size: 'sm' })}
                            >
                                Save
                            </button>
                        ) : null}
                        {form.errors.role ? <span className="text-[11px] text-danger">{form.errors.role}</span> : null}
                    </form>
                ) : (
                    <StatusBadge tone={member.is_owner ? 'info' : 'neutral'} dot={false}>
                        {member.role_label}
                        {member.is_owner ? ' · owner' : ''}
                    </StatusBadge>
                )}
            </td>
            <td className="px-4 py-3 text-xs text-muted">{member.joined_at ? formatDate(member.joined_at) : '—'}</td>
            <td className="px-4 py-3">
                <StatusBadge tone={member.status === 'active' ? 'success' : 'neutral'}>{member.status}</StatusBadge>
            </td>
            <td className="px-4 py-3 text-right">
                {editable ? (
                    <button
                        type="button"
                        onClick={() => onRemove(member)}
                        className={buttonClass({ variant: 'ghost', size: 'sm' })}
                        aria-label={`Remove ${member.name}`}
                    >
                        <Icon name="x" size={14} />
                        Remove
                    </button>
                ) : null}
            </td>
        </tr>
    );
}

export default function MembersIndex({ members, invitations, assignableRoles, canManage }) {
    const [pendingRemove, setPendingRemove] = useState(null);
    const flash = usePage().props.flash;

    const inviteForm = useForm({
        email: '',
        role: assignableRoles[0]?.value ?? 'developer',
    });

    function invite(event) {
        event.preventDefault();
        inviteForm.post('/members/invitations', {
            preserveScroll: true,
            onSuccess: () => inviteForm.reset('email'),
        });
    }

    function revokeInvitation(invitation) {
        if (!window.confirm(`Revoke the invitation for ${invitation.email}?`)) {
            return;
        }

        router.delete(`/members/invitations/${invitation.id}`, { preserveScroll: true });
    }

    function removeMember() {
        if (!pendingRemove) {
            return;
        }

        router.delete(`/members/${pendingRemove.id}`, {
            preserveScroll: true,
            onFinish: () => setPendingRemove(null),
        });
    }

    return (
        <AppLayout
            title="Members"
            description="Invite teammates and control what they can do inside this organization."
            eyebrow="Access"
        >
            <Head title="Members" />

            {flash.invitation_url ? (
                <div className="mb-5 rounded-xl border border-accent/30 bg-accent-soft p-4">
                    <div className="flex items-start gap-2.5">
                        <Icon name="users" size={17} className="mt-0.5 shrink-0 text-accent" />
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium text-accent">Invitation link</p>
                            <p className="mt-0.5 text-xs text-muted">
                                Share this single-use link with the invitee. It expires in seven days.
                            </p>
                            <div className="mt-3">
                                <CopyField value={flash.invitation_url} />
                            </div>
                        </div>
                    </div>
                </div>
            ) : null}

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                <section className="cf-card overflow-hidden">
                    <div className="flex items-center justify-between border-b border-line px-4 py-3">
                        <div>
                            <h2 className="text-sm font-semibold text-ink">Organization members</h2>
                            <p className="mt-0.5 text-xs text-faint">{members.length} active record{members.length === 1 ? '' : 's'}</p>
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px] text-left">
                            <thead>
                                <tr className="bg-surface-raised/50">
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Member</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Role</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Joined</th>
                                    <th className="px-4 py-2.5 font-mono text-[10px] tracking-[0.12em] text-faint uppercase">Status</th>
                                    <th className="px-4 py-2.5" />
                                </tr>
                            </thead>
                            <tbody>
                                {members.map((member) => (
                                    <MemberRow
                                        key={member.id}
                                        member={member}
                                        assignableRoles={assignableRoles}
                                        canManage={canManage}
                                        onRemove={setPendingRemove}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <div className="space-y-5">
                    {canManage ? (
                        <form onSubmit={invite} className="cf-card p-4">
                            <h2 className="text-sm font-semibold text-ink">Invite a member</h2>
                            <p className="mt-0.5 text-xs text-faint">The invitation link is shown once after creation.</p>

                            <div className="mt-4 space-y-3">
                                <Field label="Email address" htmlFor="invite-email" error={inviteForm.errors.email} required>
                                    <Input
                                        id="invite-email"
                                        type="email"
                                        value={inviteForm.data.email}
                                        onChange={(event) => inviteForm.setData('email', event.target.value)}
                                        placeholder="teammate@example.com"
                                        autoComplete="off"
                                        error={inviteForm.errors.email}
                                    />
                                </Field>
                                <Field label="Role" htmlFor="invite-role" error={inviteForm.errors.role} required>
                                    <Select
                                        id="invite-role"
                                        value={inviteForm.data.role}
                                        onChange={(event) => inviteForm.setData('role', event.target.value)}
                                    >
                                        {assignableRoles.map((role) => (
                                            <option key={role.value} value={role.value}>
                                                {role.label}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <button
                                    type="submit"
                                    disabled={inviteForm.processing || assignableRoles.length === 0}
                                    className={buttonClass({ variant: 'primary', className: 'w-full' })}
                                >
                                    <Icon name="plus" size={14} />
                                    {inviteForm.processing ? 'Creating…' : 'Create invitation'}
                                </button>
                            </div>
                        </form>
                    ) : null}

                    <section className="cf-card overflow-hidden">
                        <div className="border-b border-line px-4 py-3">
                            <h2 className="text-sm font-semibold text-ink">Pending invitations</h2>
                            <p className="mt-0.5 text-xs text-faint">Unaccepted links that have not expired.</p>
                        </div>

                        {invitations.length === 0 ? (
                            <EmptyState
                                icon="inbox"
                                title="No pending invitations"
                                description="Invitations appear here until they are accepted, revoked or expire."
                            />
                        ) : (
                            <ul className="divide-y divide-line">
                                {invitations.map((invitation) => (
                                    <li key={invitation.id} className="flex items-start justify-between gap-3 px-4 py-3">
                                        <div className="min-w-0">
                                            <p className="truncate text-xs font-medium text-ink">{invitation.email}</p>
                                            <p className="mt-0.5 text-[11px] text-faint">
                                                {invitation.role_label} · invited by {invitation.invited_by} · expires{' '}
                                                {formatRelative(invitation.expires_at)}
                                            </p>
                                        </div>
                                        {canManage ? (
                                            <button
                                                type="button"
                                                onClick={() => revokeInvitation(invitation)}
                                                className={buttonClass({ variant: 'ghost', size: 'sm' })}
                                            >
                                                Revoke
                                            </button>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>

            <ConfirmDialog
                open={pendingRemove !== null}
                onClose={() => setPendingRemove(null)}
                onConfirm={removeMember}
                title={pendingRemove ? `Remove ${pendingRemove.name}?` : 'Remove member?'}
                description="They lose access to this organization immediately. The audit log keeps the removal record."
                confirmLabel="Remove member"
            />
        </AppLayout>
    );
}
