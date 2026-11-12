<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithAgents;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class BootstrapWebTest extends TestCase
{
    use InteractsWithAgents, InteractsWithOrganizations, RefreshDatabase;

    private const MACHINE_ID = '5c9f0f7a6e0e4d439f0f2b1c3d4e5f60';

    public function test_onboarding_lists_unclaimed_bootstrap_nodes_without_exposing_credentials(): void
    {
        $user = User::factory()->create();

        $server = Server::factory()->create([
            'organization_id' => null,
            'machine_id' => self::MACHINE_ID,
            'is_bootstrap' => true,
        ]);

        $token = Str::random(64);

        $enrollment = ServerEnrollment::query()->create([
            'organization_id' => null,
            'server_id' => $server->id,
            'token_hash' => ServerEnrollment::hashToken($token),
            'purpose' => 'bootstrap',
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->actingAs($user)
            ->get(route('onboarding'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Onboarding')
                ->has('bootstrapNodes', 1)
                ->where('bootstrapNodes.0.id', $server->id)
                ->where('bootstrapNodes.0.name', $server->name)
                ->where('bootstrapNodes.0.hostname', $server->hostname)
                ->where('bootstrapNodes.0.machine_id', self::MACHINE_ID)
                ->where('bootstrapNodes.0.status', 'installation_pending')
                ->where('bootstrapNodes.0.status_label', $server->status->label())
                ->where('bootstrapNodes.0.agent_enrolled', false)
                ->where('bootstrapNodes.0.enrollment_expires_at', $enrollment->expires_at->toIso8601String())
                ->where('bootstrapNodes.0.last_heartbeat_at', null))
            ->assertDontSee($token);
    }

    public function test_onboarding_marks_bootstrap_nodes_whose_agent_has_enrolled(): void
    {
        $user = User::factory()->create();

        $server = Server::factory()->create([
            'organization_id' => null,
            'machine_id' => self::MACHINE_ID,
            'is_bootstrap' => true,
        ]);

        $this->issueServerCertificate($server);

        $this->actingAs($user)
            ->get(route('onboarding'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Onboarding')
                ->has('bootstrapNodes', 1)
                ->where('bootstrapNodes.0.agent_enrolled', true)
                ->where('bootstrapNodes.0.enrollment_expires_at', null));
    }

    public function test_server_pages_expose_bootstrap_origin_and_claim_time(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $claimedAt = now()->subHour();

        $server = Server::factory()->forOrganization($organization)->create([
            'machine_id' => self::MACHINE_ID,
            'is_bootstrap' => true,
            'claimed_at' => $claimedAt,
        ]);

        $this->actingIn($organization, $owner)
            ->get(route('servers.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Servers/Index')
                ->has('servers.data', 1)
                ->where('servers.data.0.is_bootstrap', true)
                ->where('servers.data.0.claimed_at', $claimedAt->toIso8601String()));

        $this->actingIn($organization, $owner)
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Servers/Show')
                ->where('server.is_bootstrap', true)
                ->where('server.claimed_at', $claimedAt->toIso8601String()));
    }

    public function test_manually_registered_servers_are_not_flagged_as_bootstrap(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $server = Server::factory()->forOrganization($organization)->create([
            'is_bootstrap' => false,
            'claimed_at' => null,
        ]);

        $this->actingIn($organization, $owner)
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('server.is_bootstrap', false)
                ->where('server.claimed_at', null));
    }
}
