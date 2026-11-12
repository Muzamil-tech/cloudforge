<?php

namespace Tests\Feature;

use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerEnrollment;
use App\Domains\Infrastructure\Services\BootstrapCredentialWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class BootstrapCredentialTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/bootstrap-'.Str::uuid());
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_writer_produces_a_shell_credential_and_a_token_free_status_document(): void
    {
        config(['cloudforge.bootstrap.control_plane_url' => 'https://cloudforge.example.com']);

        $server = $this->bootstrapServer();
        $token = Str::random(64);
        $enrollment = $this->enrollment($server, $token);

        $writer = $this->writer();
        $result = $writer->write($server, $enrollment, $token);

        $this->assertSame($this->credentialPath(), $result['credential_path']);
        $this->assertSame($this->statusPath(), $result['status_path']);
        $this->assertFalse($result['already_enrolled']);

        $credential = File::get($this->credentialPath());

        $this->assertStringContainsString("CLOUDFORGE_ENROLLMENT_TOKEN='{$token}'", $credential);
        $this->assertStringContainsString("CLOUDFORGE_CONTROL_PLANE_URL='https://cloudforge.example.com'", $credential);
        $this->assertStringContainsString("CLOUDFORGE_SERVER_ID='{$server->id}'", $credential);

        $statusContents = File::get($this->statusPath());
        $status = json_decode($statusContents, true, flags: JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($token, $statusContents);
        $this->assertSame($server->id, $status['server']['id']);
        $this->assertSame($server->name, $status['server']['name']);
        $this->assertSame($server->machine_id, $status['server']['machine_id']);
        $this->assertTrue($status['server']['is_bootstrap']);
        $this->assertNull($status['server']['organization_id']);
        $this->assertSame($enrollment->id, $status['enrollment']['id']);
        $this->assertSame('bootstrap', $status['enrollment']['purpose']);
        $this->assertSame($enrollment->expires_at->toIso8601String(), $status['enrollment']['expires_at']);
        $this->assertSame($this->credentialPath(), $status['credential_path']);
        $this->assertFalse($status['already_enrolled']);

        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->assertSame(0600, fileperms($this->credentialPath()) & 0777);
            $this->assertSame(0600, fileperms($this->statusPath()) & 0777);
        }

        $this->assertSame($status, $writer->readStatus());
    }

    public function test_writer_removes_a_stale_credential_when_the_agent_is_already_enrolled(): void
    {
        $server = $this->bootstrapServer();
        $token = Str::random(64);

        $writer = $this->writer();
        $writer->write($server, $this->enrollment($server, $token), $token);

        $this->assertTrue(File::exists($this->credentialPath()));

        $result = $writer->write($server, null, null);

        $this->assertTrue($result['already_enrolled']);
        $this->assertFalse(File::exists($this->credentialPath()));

        $status = $writer->readStatus();

        $this->assertNotNull($status);
        $this->assertTrue($status['already_enrolled']);
        $this->assertNull($status['enrollment']);
        $this->assertStringNotContainsString($token, File::get($this->statusPath()));
    }

    public function test_writer_rejects_a_malformed_token(): void
    {
        $server = $this->bootstrapServer();

        $this->expectException(RuntimeException::class);

        $this->writer()->write($server, $this->enrollment($server, Str::random(32)), 'not a valid token');
    }

    public function test_writer_requires_the_enrollment_and_token_to_be_written_together(): void
    {
        $server = $this->bootstrapServer();

        $this->expectException(RuntimeException::class);

        $this->writer()->write($server, $this->enrollment($server, Str::random(64)), null);
    }

    public function test_reading_a_missing_status_document_returns_null(): void
    {
        $this->assertNull($this->writer()->readStatus());
    }

    private function writer(): BootstrapCredentialWriter
    {
        return new BootstrapCredentialWriter($this->credentialPath(), $this->statusPath());
    }

    private function credentialPath(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'bootstrap.env';
    }

    private function statusPath(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'bootstrap.json';
    }

    private function bootstrapServer(): Server
    {
        return Server::factory()->create([
            'organization_id' => null,
            'machine_id' => '5c9f0f7a6e0e4d439f0f2b1c3d4e5f60',
            'is_bootstrap' => true,
        ]);
    }

    private function enrollment(Server $server, string $token): ServerEnrollment
    {
        return ServerEnrollment::query()->create([
            'organization_id' => null,
            'server_id' => $server->id,
            'token_hash' => ServerEnrollment::hashToken($token),
            'purpose' => 'bootstrap',
            'expires_at' => now()->addMinutes(30),
        ]);
    }
}
