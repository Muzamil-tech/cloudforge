<?php

namespace App\Domains\Operations\Enums;

enum AuditAction: string
{
    case UserRegistered = 'user.registered';
    case UserLoggedIn = 'user.logged_in';
    case UserLoggedOut = 'user.logged_out';
    case OrganizationCreated = 'organization.created';
    case OrganizationUpdated = 'organization.updated';
    case MemberInvited = 'organization.member_invited';
    case MemberJoined = 'organization.member_joined';
    case MemberRoleChanged = 'organization.member_role_changed';
    case MemberRemoved = 'organization.member_removed';
    case InvitationRevoked = 'organization.invitation_revoked';
    case ApiTokenCreated = 'api_token.created';
    case ApiTokenRevoked = 'api_token.revoked';
    case ProjectCreated = 'project.created';
    case ProjectUpdated = 'project.updated';
    case ProjectDeleted = 'project.deleted';
    case ServerCreated = 'server.created';
    case ServerUpdated = 'server.updated';
    case ServerDeleted = 'server.deleted';
    case ServerEnrollmentIssued = 'server.enrollment_issued';
    case ServerBootstrapProvisioned = 'server.bootstrap_provisioned';
    case ServerBootstrapClaimed = 'server.bootstrap_claimed';
    case ServerEnrolled = 'server.enrolled';
    case ServerHeartbeat = 'server.heartbeat';
    case ServerDrained = 'server.drained';
    case ServerMaintenance = 'server.maintenance';
    case ServerResumed = 'server.resumed';
    case ServerCertificatesRevoked = 'server.certificates_revoked';
    case ServerCertificateRotated = 'server.certificate_rotated';
    case ServerUnreachable = 'server.unreachable';
    case ServerRecovered = 'server.recovered';
    case ResourceProvisioned = 'resource.provisioned';
    case ResourceCreated = 'resource.created';
    case ResourceStarted = 'resource.started';
    case ResourceStopped = 'resource.stopped';
    case ResourceDeleted = 'resource.deleted';
    case ResourceFailed = 'resource.failed';
    case ResourceCredentialsRevealed = 'resource.credentials_revealed';
    case AgentCommandIssued = 'agent_command.issued';
    case AgentCommandAcknowledged = 'agent_command.acknowledged';
    case AgentCommandCompleted = 'agent_command.completed';
    case AgentCommandFailed = 'agent_command.failed';
    case AgentCommandCancelled = 'agent_command.cancelled';
}
