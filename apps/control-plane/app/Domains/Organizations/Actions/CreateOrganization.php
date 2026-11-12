<?php

namespace App\Domains\Organizations\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Actions\ClaimBootstrapServers;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Enums\OrganizationRole;
use App\Domains\Organizations\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateOrganization
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ClaimBootstrapServers $claimBootstrapServers,
    ) {}

    /**
     * @param  array{name: string, slug?: string|null, plan?: string|null}  $attributes
     */
    public function execute(User $owner, array $attributes): Organization
    {
        return DB::transaction(function () use ($owner, $attributes): Organization {
            $slug = $attributes['slug'] ?? null;

            if ($slug === null || trim($slug) === '') {
                $slug = Organization::uniqueSlug($attributes['name']);
            }

            $organization = Organization::query()->create([
                'name' => $attributes['name'],
                'slug' => Str::slug($slug),
                'owner_user_id' => $owner->id,
                'plan' => $attributes['plan'] ?? 'free',
                'status' => 'active',
                'settings' => [],
            ]);

            Membership::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $owner->id,
                'role' => OrganizationRole::Owner,
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ]);

            $this->auditLogger->record(
                AuditAction::OrganizationCreated,
                $organization,
                $organization,
                ['name' => $organization->name],
                $owner,
            );

            $this->claimBootstrapServers->execute($organization, $owner);

            return $organization;
        });
    }
}
