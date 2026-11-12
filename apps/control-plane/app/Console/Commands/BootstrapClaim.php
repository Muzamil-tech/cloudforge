<?php

namespace App\Console\Commands;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Actions\ClaimBootstrapServers;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Console\Command;

class BootstrapClaim extends Command
{
    protected $signature = 'cloudforge:bootstrap:claim
                            {--organization= : Organization slug or UUID (defaults to the only organization)}
                            {--user= : Email of the owner or admin performing the claim (defaults to the organization owner)}
                            {--server= : Only claim the unclaimed bootstrap server with this UUID, name, or machine-id}';

    protected $description = 'Claim unclaimed bootstrap servers for an organization';

    public function handle(ClaimBootstrapServers $claimBootstrapServers): int
    {
        $organization = $this->resolveOrganization();

        if ($organization === null) {
            return self::FAILURE;
        }

        $actor = $this->resolveActor($organization);

        if ($actor === null) {
            return self::FAILURE;
        }

        $server = null;
        $serverOption = $this->option('server');

        if (is_string($serverOption) && trim($serverOption) !== '') {
            $server = $this->resolveServer(trim($serverOption));

            if ($server === null) {
                return self::FAILURE;
            }

            if ($server->organization_id !== null) {
                if ($server->organization?->is($organization)) {
                    $this->info("{$server->name} is already claimed by {$organization->name}.");

                    return self::SUCCESS;
                }

                $this->error("{$server->name} is already claimed by another organization.");

                return self::FAILURE;
            }
        }

        $claimed = $claimBootstrapServers->execute($organization, $actor, $server);

        if ($claimed->isEmpty()) {
            $this->info('No unclaimed bootstrap servers were found.');

            return self::SUCCESS;
        }

        foreach ($claimed as $claimedServer) {
            $this->line("Claimed {$claimedServer->name} ({$claimedServer->id}).");
        }

        $this->info("{$claimed->count()} bootstrap server(s) claimed for {$organization->name}.");

        return self::SUCCESS;
    }

    private function resolveOrganization(): ?Organization
    {
        $identifier = $this->option('organization');

        if (is_string($identifier) && trim($identifier) !== '') {
            $identifier = trim($identifier);

            $organization = Organization::query()
                ->where('id', $identifier)
                ->orWhere('slug', $identifier)
                ->first();

            if ($organization === null) {
                $this->error("No organization matches '{$identifier}'.");

                return null;
            }

            return $organization;
        }

        $organizations = Organization::query()->orderBy('name')->get();

        if ($organizations->isEmpty()) {
            $this->error('No organization exists yet. Create one through the dashboard first.');

            return null;
        }

        if ($organizations->count() > 1) {
            $this->error('More than one organization exists. Pass --organization=<slug|uuid>.');

            foreach ($organizations as $organization) {
                $this->line("  - {$organization->slug} ({$organization->name})");
            }

            return null;
        }

        return $organizations->first();
    }

    private function resolveActor(Organization $organization): ?User
    {
        $email = $this->option('user');

        if (is_string($email) && trim($email) !== '') {
            $user = User::query()->where('email', trim($email))->first();

            if ($user === null) {
                $this->error("No user matches '{$email}'.");

                return null;
            }
        } else {
            $user = $organization->owner;
        }

        if ($user === null) {
            $this->error('The organization has no owner to attribute the claim to.');

            return null;
        }

        $role = $organization->roleFor($user);

        if ($role === null || ! $organization->isMember($user)) {
            $this->error("{$user->email} is not an active member of {$organization->name}.");

            return null;
        }

        if (! $role->canManageInfrastructure()) {
            $this->error("{$user->email} has the {$role->value} role and cannot claim infrastructure.");

            return null;
        }

        return $user;
    }

    private function resolveServer(string $identifier): ?Server
    {
        $server = Server::query()
            ->where('is_bootstrap', true)
            ->where(function ($query) use ($identifier): void {
                $query->where('id', $identifier)
                    ->orWhere('name', $identifier)
                    ->orWhere('machine_id', $identifier);
            })
            ->first();

        if ($server === null) {
            $this->error("No bootstrap server matches '{$identifier}'.");

            return null;
        }

        return $server;
    }
}
