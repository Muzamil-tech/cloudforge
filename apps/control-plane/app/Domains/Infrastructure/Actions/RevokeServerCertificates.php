<?php

namespace App\Domains\Infrastructure\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerCertificate;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class RevokeServerCertificates
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Server $server, string $reason, ?User $actor = null): int
    {
        return DB::transaction(function () use ($server, $reason, $actor): int {
            $revoked = ServerCertificate::query()
                ->where('server_id', $server->id)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revocation_reason' => $reason,
                ]);

            if ($revoked > 0) {
                $server->forceFill(['status' => ServerStatus::Error])->save();

                $this->auditLogger->record(
                    AuditAction::ServerCertificatesRevoked,
                    $server->organization,
                    $server,
                    ['reason' => $reason, 'count' => $revoked],
                    $actor,
                );
            }

            return $revoked;
        });
    }
}
