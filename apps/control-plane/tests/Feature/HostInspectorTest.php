<?php

namespace Tests\Feature;

use App\Domains\Infrastructure\Services\DockerSocketProbe;
use App\Domains\Infrastructure\Services\HostInspector;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class HostInspectorTest extends TestCase
{
    private string $fixtureDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureDirectory = storage_path('framework/testing/host-'.Str::uuid());
        File::ensureDirectoryExists($this->fixtureDirectory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureDirectory);

        parent::tearDown();
    }

    public function test_os_release_prefers_pretty_name_and_unquotes_values(): void
    {
        $contents = implode("\n", [
            '# comment line',
            'NAME="Ubuntu"',
            'VERSION="24.04.1 LTS (Noble Numbat)"',
            'VERSION_ID="24.04"',
            'PRETTY_NAME="Ubuntu 24.04.1 LTS"',
        ]);

        $this->assertSame('Ubuntu 24.04.1 LTS', HostInspector::parseOsRelease($contents));
    }

    public function test_os_release_falls_back_to_name_and_version_id(): void
    {
        $contents = "NAME=Debian\nVERSION_ID=\"12\"\n";

        $this->assertSame('Debian 12', HostInspector::parseOsRelease($contents));
    }

    public function test_os_release_returns_null_without_a_name(): void
    {
        $this->assertNull(HostInspector::parseOsRelease("ID=linux\n"));
        $this->assertNull(HostInspector::parseOsRelease(''));
    }

    public function test_cpu_cores_counts_processor_entries(): void
    {
        $contents = implode("\n", [
            'processor	: 0',
            'vendor_id	: GenuineIntel',
            'processor	: 1',
            'vendor_id	: GenuineIntel',
            'processor	: 2',
        ]);

        $this->assertSame(3, HostInspector::parseCpuCores($contents));
        $this->assertNull(HostInspector::parseCpuCores('vendor_id : GenuineIntel'));
    }

    public function test_mem_total_is_converted_from_kilobytes_to_bytes(): void
    {
        $contents = "MemTotal:       16384000 kB\nMemFree:         1234567 kB\n";

        $this->assertSame(16384000 * 1024, HostInspector::parseMemTotalBytes($contents));
        $this->assertNull(HostInspector::parseMemTotalBytes('MemFree: 1024 kB'));
        $this->assertNull(HostInspector::parseMemTotalBytes("MemTotal: 0 kB\n"));
    }

    public function test_machine_id_accepts_only_thirty_two_hexadecimal_characters(): void
    {
        $canonical = '5c9f0f7a6e0e4d439f0f2b1c3d4e5f60';

        $this->assertSame($canonical, HostInspector::parseMachineId($canonical."\n"));
        $this->assertSame($canonical, HostInspector::parseMachineId(strtoupper($canonical)));
        $this->assertNull(HostInspector::parseMachineId("uninitialized\n"));
        $this->assertNull(HostInspector::parseMachineId('not-a-machine-id'));
        $this->assertNull(HostInspector::normalizeMachineId($canonical.'0'));
        $this->assertNull(HostInspector::normalizeMachineId(''));
    }

    public function test_architecture_normalization_maps_host_values(): void
    {
        $this->assertSame('x86_64', HostInspector::normalizeArchitecture('AMD64'));
        $this->assertSame('x86_64', HostInspector::normalizeArchitecture('x64'));
        $this->assertSame('aarch64', HostInspector::normalizeArchitecture('arm64'));
        $this->assertSame('aarch64', HostInspector::normalizeArchitecture('AArch64'));
        $this->assertSame('x86', HostInspector::normalizeArchitecture('i686'));
        $this->assertSame('unknown', HostInspector::normalizeArchitecture('   '));
        $this->assertSame('riscv64', HostInspector::normalizeArchitecture('RISCV64'));
    }

    public function test_interface_parsing_skips_down_loopback_and_link_local_addresses(): void
    {
        $interfaces = [
            'lo' => [
                'up' => true,
                'unicast' => [
                    ['address' => '127.0.0.1'],
                    ['address' => '::1'],
                ],
            ],
            'eth0' => [
                'up' => true,
                'unicast' => [
                    ['address' => '10.0.0.5'],
                    ['address' => '192.168.1.10'],
                    ['address' => '169.254.1.1'],
                    ['address' => 'invalid'],
                ],
            ],
            'eth1' => [
                'up' => false,
                'unicast' => [
                    ['address' => '203.0.113.9'],
                ],
            ],
            'eth2' => [
                'up' => true,
                'unicast' => ['198.51.100.7'],
            ],
        ];

        $this->assertSame(
            ['10.0.0.5', '192.168.1.10', '198.51.100.7'],
            HostInspector::parseInterfaces($interfaces),
        );
    }

    public function test_private_public_and_reserved_address_classification(): void
    {
        $this->assertTrue(HostInspector::isPrivateAddress('10.1.2.3'));
        $this->assertTrue(HostInspector::isPrivateAddress('172.16.0.1'));
        $this->assertTrue(HostInspector::isPrivateAddress('192.168.1.1'));
        $this->assertTrue(HostInspector::isPrivateAddress('100.64.0.1'));
        $this->assertTrue(HostInspector::isPrivateAddress('fd00::1'));
        $this->assertFalse(HostInspector::isPrivateAddress('203.0.113.9'));

        $this->assertTrue(HostInspector::isPublicAddress('203.0.113.9'));
        $this->assertTrue(HostInspector::isPublicAddress('2001:db8::1'));
        $this->assertFalse(HostInspector::isPublicAddress('10.1.2.3'));
        $this->assertFalse(HostInspector::isPublicAddress('127.0.0.1'));
        $this->assertFalse(HostInspector::isPublicAddress('169.254.0.1'));

        $this->assertTrue(HostInspector::isLoopbackOrLinkLocal('127.0.0.1'));
        $this->assertTrue(HostInspector::isLoopbackOrLinkLocal('169.254.10.10'));
        $this->assertTrue(HostInspector::isLoopbackOrLinkLocal('::1'));
        $this->assertTrue(HostInspector::isLoopbackOrLinkLocal('fe80::1'));
        $this->assertFalse(HostInspector::isLoopbackOrLinkLocal('10.0.0.1'));
    }

    public function test_inspection_reads_real_fixture_sources(): void
    {
        $osRelease = $this->fixture('os-release', "PRETTY_NAME=\"Ubuntu 24.04 LTS\"\n");
        $machineId = $this->fixture('machine-id', "5c9f0f7a6e0e4d439f0f2b1c3d4e5f60\n");
        $memInfo = $this->fixture('meminfo', "MemTotal: 8192000 kB\n");
        $cpuInfo = $this->fixture('cpuinfo', "processor : 0\nprocessor : 1\nprocessor : 2\nprocessor : 3\n");

        $inspector = new HostInspector(
            dockerProbe: null,
            osReleasePath: $osRelease,
            machineIdPath: $machineId,
            dbusMachineIdPath: $this->fixtureDirectory.'/missing-dbus-machine-id',
            memInfoPath: $memInfo,
            cpuInfoPath: $cpuInfo,
            diskPath: $this->fixtureDirectory,
        );

        $facts = $inspector->inspect();

        $this->assertNotSame('', $facts['hostname']);
        $this->assertSame('Ubuntu 24.04 LTS', $facts['operating_system']);
        $this->assertSame(4, $facts['cpu_cores']);
        $this->assertSame(8192000 * 1024, $facts['total_memory_bytes']);
        $this->assertSame('5c9f0f7a6e0e4d439f0f2b1c3d4e5f60', $facts['machine_id']);
        $this->assertIsInt($facts['total_disk_bytes']);
        $this->assertIsInt($facts['available_disk_bytes']);
        $this->assertNull($facts['docker_version']);
        $this->assertIsArray($facts['addresses']);
        $this->assertArrayHasKey('architecture', $facts);
    }

    public function test_missing_sources_report_null_instead_of_fabricated_values(): void
    {
        $inspector = new HostInspector(
            osReleasePath: $this->fixtureDirectory.'/missing-os-release',
            machineIdPath: $this->fixtureDirectory.'/missing-machine-id',
            dbusMachineIdPath: $this->fixtureDirectory.'/missing-dbus-machine-id',
            memInfoPath: $this->fixtureDirectory.'/missing-meminfo',
            cpuInfoPath: $this->fixtureDirectory.'/missing-cpuinfo',
            diskPath: $this->fixtureDirectory.'/missing-disk',
        );

        $this->assertNull($inspector->machineId());
        $this->assertNull($inspector->cpuCores());
        $this->assertNull($inspector->totalMemoryBytes());
        $this->assertNotSame('', (string) $inspector->operatingSystem());
    }

    public function test_machine_id_override_is_normalized_and_preferred(): void
    {
        $inspector = new HostInspector(
            machineIdPath: $this->fixture('machine-id', "0123456789abcdef0123456789abcdef\n"),
            machineIdOverride: '5C9F0F7A6E0E4D439F0F2B1C3D4E5F60',
        );

        $this->assertSame('5c9f0f7a6e0e4d439f0f2b1c3d4e5f60', $inspector->machineId());

        $invalid = new HostInspector(machineIdOverride: 'configured-by-hand');

        $this->assertNull($invalid->machineId());
    }

    public function test_docker_socket_probe_parses_only_successful_version_responses(): void
    {
        $success = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n".json_encode(['Version' => '27.3.1']);

        $this->assertSame('27.3.1', DockerSocketProbe::parseVersion($success));
        $this->assertSame('26.1.0', DockerSocketProbe::parseVersion(
            "HTTP/1.0 200 OK\r\n\r\n".json_encode(['version' => '26.1.0']),
        ));
        $this->assertNull(DockerSocketProbe::parseVersion(
            "HTTP/1.1 500 Internal Server Error\r\n\r\n".json_encode(['Version' => '27.3.1']),
        ));
        $this->assertNull(DockerSocketProbe::parseVersion("HTTP/1.1 200 OK\r\n\r\nnot-json"));
        $this->assertNull(DockerSocketProbe::parseVersion('garbage'));
    }

    private function fixture(string $name, string $contents): string
    {
        $path = $this->fixtureDirectory.DIRECTORY_SEPARATOR.$name;

        File::put($path, $contents);

        return $path;
    }
}
