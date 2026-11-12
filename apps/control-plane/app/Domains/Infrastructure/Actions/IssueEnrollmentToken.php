<?php

namespace App\Domains\Infrastructure\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Enums\EnrollmentPurpose;
use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerEnrollment;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueEnrollmentToken
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return array{enrollment: ServerEnrollment, token: string}
     */
    public function execute(
        Server $server,
        ?User $actor = null,
        EnrollmentPurpose $purpose = EnrollmentPurpose::Installation,
        ?CarbonInterface $expiresAt = null,
    ): array {
        if ($server->activeCertificate() !== null) {
            throw ValidationException::withMessages([
                'server' => 'This server already has an active certificate. Revoke or rotate it instead.',
            ]);
        }

        if ($purpose->isBootstrap() && $server->organization_id !== null) {
            throw ValidationException::withMessages([
                'server' => 'A bootstrap enrollment can only be issued for an unclaimed server.',
            ]);
        }

        return DB::transaction(function () use ($server, $actor, $purpose, $expiresAt): array {
            $token = ServerEnrollment::generateToken();

            ServerEnrollment::query()
                ->where('server_id', $server->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $enrollment = ServerEnrollment::query()->create([
                'organization_id' => $server->organization_id,
                'server_id' => $server->id,
                'token_hash' => ServerEnrollment::hashToken($token),
                'purpose' => $purpose,
                'expires_at' => $expiresAt ?? now()->addMinutes((int) config('cloudforge.agent.enrollment_token_ttl_minutes', 15)),
                'created_by_user_id' => $actor?->id,
            ]);

            $server->forceFill(['status' => ServerStatus::InstallationPending])->save();

            $this->auditLogger->record(
                AuditAction::ServerEnrollmentIssued,
                $server->organization,
                $server,
                [
                    'purpose' => $purpose->value,
                    'expires_at' => $enrollment->expires_at->toIso8601String(),
                ],
                $actor,
            );

            return ['enrollment' => $enrollment, 'token' => $token];
        });
    }
}
