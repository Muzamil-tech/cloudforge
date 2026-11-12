<?php

namespace App\Domains\Organizations\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\OrganizationApiToken;
use Illuminate\Support\Str;

class CreateApiToken
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  list<string>  $abilities
     * @return array{token: OrganizationApiToken, plaintext: string}
     */
    public function execute(
        Organization $organization,
        User $actor,
        string $name,
        array $abilities,
        ?\DateTimeInterface $expiresAt = null,
    ): array {
        $plaintext = 'cf_'.Str::random(48);

        $token = OrganizationApiToken::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'token_hash' => OrganizationApiToken::hashToken($plaintext),
            'abilities' => array_values(array_unique($abilities)),
            'expires_at' => $expiresAt,
            'created_by_user_id' => $actor->id,
        ]);

        $this->auditLogger->record(
            AuditAction::ApiTokenCreated,
            $organization,
            $token,
            ['name' => $name, 'abilities' => $token->abilities],
            $actor,
        );

        return ['token' => $token, 'plaintext' => $plaintext];
    }
}
