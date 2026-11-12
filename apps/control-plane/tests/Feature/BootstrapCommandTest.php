<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Services\HostInspector;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class BootstrapCommandTest extends TestCase
{
    use RefreshDatabase;

    private const MACHINE_ID = '5c9f0f7a6e0e4d439f0f2b1c3d4e5f60';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/bootstrap-command-'.Str::uuid());
        File::ensureDirectoryExists($this->directory);

        $this->app->instance(HostInspector::class, new HostInspector(
            machineIdOverride: self::MACHINE_ID,
            osReleasePath: $this->directory.'/missing-os-release',
            machineIdPath: $this->directory.'/missing-machine-id',
            dbusMachineIdPath: $this->directory.'/missing-dbus-machine-id',
            memInfoPath: $this->directory.'/missing-meminfo',
            cpuInfoPath: $this->directory.'/missing-cpuinfo',
            diskPath: $this->directory,
        ));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_bootstrap_command_registers_an_unclaimed_server_and_never_prints_the_token(): void
    {
        ['exit_code' => $exitCode, 'output' => $output] = $this->runCommand('cloudforge:bootstrap', [
            '--name' => 'primary-node',
            '--url' => 'https://cloudforge.example.com',
            '--credential-path' => $this->credentialPath(),
            '--status-path' => $this->statusPath(),
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $summary = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($summary['created']);
        $this->assertFalse($summary['already_enrolled']);
        $this->assertSame('primary-node', $summary['server_name']);
        $this->assertSame(self::MACHINE_ID, $summary['machine_id']);
        $this->assertSame($this->credentialPath(), $summary['credential_path']);
        $this->assertSame($this->statusPath(), $summary['status_path']);
        $this->assertArrayNotHasKey('token', $summary);

        $credential = File::get($this->credentialPath());

        $this->assertSame(1, preg_match("/CLOUDFORGE_ENROLLMENT_TOKEN='([^']+)'/", $credential, $matches));

        $token = $matches[1];

        $this->assertStringNotContainsString($token, $output);
        $this->assertStringNotContainsString($token, File::get($this->statusPath()));

        $server = Server::query()->sole();

        $this->assertSame($summary['server_id'], $server->id);
        $this->assertNull($server->organization_id);
        $this->assertTrue($server->isBootstrap());
        $this->assertSame(self::MACHINE_ID, $server->machine_id);
        $this->assertSame(1, $server->enrollments()->where('purpose', 'bootstrap')->count());
    }

    public function test_bootstrap_command_is_idempotent_when_re_run(): void
    {
        $parameters = [
            '--name' => 'primary-node',
            '--credential-path' => $this->credentialPath(),
            '--status-path' => $this->statusPath(),
            '--json' => true,
        ];

        $result = $this->runCommand('cloudforge:bootstrap', $parameters);

        $this->assertSame(0, $result['exit_code']);
        $first = json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);
        $firstName = $first['server_name'];

        $secondResult = $this->runCommand('cloudforge:bootstrap', $parameters);

        $this->assertSame(0, $secondResult['exit_code']);
        $second = json_decode($secondResult['output'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($firstName, $second['server_name']);
        $this->assertFalse($second['created']);
        $this->assertSame(1, Server::query()->count());
        $this->assertSame(1, Server::query()->sole()->enrollments()->whereNull('consumed_at')->count());
    }

    public function test_bootstrap_command_rejects_an_invalid_control_plane_url(): void
    {
        ['exit_code' => $exitCode, 'output' => $output] = $this->runCommand('cloudforge:bootstrap', [
            '--url' => 'not-a-url',
            '--credential-path' => $this->credentialPath(),
            '--status-path' => $this->statusPath(),
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not a valid URL', $output);
        $this->assertSame(0, Server::query()->count());
    }

    public function test_bootstrap_status_command_reports_live_state_as_json(): void
    {
        $this->assertSame(0, $this->runCommand('cloudforge:bootstrap', [
            '--name' => 'primary-node',
            '--credential-path' => $this->credentialPath(),
            '--status-path' => $this->statusPath(),
        ])['exit_code']);

        config([
            'cloudforge.bootstrap.credential_path' => $this->credentialPath(),
            'cloudforge.bootstrap.status_path' => $this->statusPath(),
        ]);

        ['exit_code' => $exitCode, 'output' => $output] = $this->runCommand('cloudforge:bootstrap:status', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $status = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('primary-node', $status['server']['name']);
        $this->assertSame(self::MACHINE_ID, $status['server']['machine_id']);
        $this->assertFalse($status['live']['agent_enrolled']);
        $this->assertNull($status['live']['claimed_by']);
        $this->assertSame('installation_pending', $status['live']['status']);
        $this->assertStringNotContainsString('TOKEN', json_encode($status, JSON_THROW_ON_ERROR));
    }

    public function test_bootstrap_status_command_fails_without_a_status_document(): void
    {
        config([
            'cloudforge.bootstrap.status_path' => $this->directory.'/missing-status.json',
        ]);

        ['exit_code' => $exitCode, 'output' => $output] = $this->runCommand('cloudforge:bootstrap:status');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No readable bootstrap status document', $output);
    }

    public function test_bootstrap_claim_command_claims_for_the_resolved_organization(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->ownedBy($owner)->create();

        $server = Server::factory()->create([
            'organization_id' => null,
            'machine_id' => self::MACHINE_ID,
            'is_bootstrap' => true,
        ]);

        ['exit_code' => $exitCode, 'output' => $output] = $this->runCommand('cloudforge:bootstrap:claim', [
            '--organization' => $organization->slug,
            '--user' => $owner->email,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("Claimed {$server->name}", $output);
        $this->assertSame($organization->id, $server->refresh()->organization_id);
        $this->assertNotNull($server->claimed_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ServerBootstrapClaimed->value,
            'organization_id' => $organization->id,
            'actor_id' => $owner->id,
            'resource_id' => $server->id,
        ]);
    }

    public function test_bootstrap_claim_command_can_target_a_single_server(): void
    {
        $organization = Organization::factory()->ownedBy(User::factory()->create())->create();

        $first = Server::factory()->create([
            'organization_id' => null,
            'machine_id' => '0123456789abcdef0123456789abcdef',
            'is_bootstrap' => true,
        ]);
        $second = Server::factory()->create([
            'organization_id' => null,
            'machine_id' => 'fedcba9876543210fedcba9876543210',
            'is_bootstrap' => true,
        ]);

        $this->assertSame(0, $this->runCommand('cloudforge:bootstrap:claim', [
            '--organization' => $organization->id,
            '--server' => $first->machine_id,
        ])['exit_code']);

        $this->assertSame($organization->id, $first->refresh()->organization_id);
        $this->assertNull($second->refresh()->organization_id);
    }

    public function test_bootstrap_claim_command_reports_a_server_owned_by_another_organization(): void
    {
        $organization = Organization::factory()->ownedBy(User::factory()->create())->create();
        $other = Organization::factory()->ownedBy(User::factory()->create())->create();

        $server = Server::factory()->forOrganization($other)->create([
            'machine_id' => self::MACHINE_ID,
            'is_bootstrap' => true,
        ]);

        ['exit_code' => $exitCode, 'output' => $output] = $this->runCommand('cloudforge:bootstrap:claim', [
            '--organization' => $organization->slug,
            '--server' => $server->id,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('already claimed by another organization', $output);
        $this->assertSame($other->id, $server->refresh()->organization_id);
    }

    public function test_bootstrap_claim_command_reports_a_same_organization_claim_as_success(): void
    {
        $organization = Organization::factory()->ownedBy(User::factory()->create())->create();
        $server = Server::factory()->forOrganization($organization)->create([
            'machine_id' => self::MACHINE_ID,
            'is_bootstrap' => true,
        ]);

        ['exit_code' => $exitCode, 'output' => $output] = $this->runCommand('cloudforge:bootstrap:claim', [
            '--server' => $server->id,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('already claimed by', $output);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{exit_code: int, output: string}
     */
    private function runCommand(string $command, array $parameters = []): array
    {
        $exitCode = Artisan::call($command, $parameters);

        return ['exit_code' => $exitCode, 'output' => trim(Artisan::output())];
    }

    private function credentialPath(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'bootstrap.env';
    }

    private function statusPath(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'bootstrap.json';
    }
}
