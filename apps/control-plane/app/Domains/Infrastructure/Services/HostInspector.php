<?php

namespace App\Domains\Infrastructure\Services;

/**
 * Collects host facts for a self-hosted bootstrap installation.
 *
 * Every value comes from a real host source. Missing or unreadable sources
 * return null rather than a fabricated default, so the bootstrap command can
 * refuse to register a node it could not inspect.
 */
class HostInspector
{
    public function __construct(
        private readonly ?DockerSocketProbe $dockerProbe = null,
        private readonly string $osReleasePath = '/etc/os-release',
        private readonly string $machineIdPath = '/etc/machine-id',
        private readonly string $dbusMachineIdPath = '/var/lib/dbus/machine-id',
        private readonly string $memInfoPath = '/proc/meminfo',
        private readonly string $cpuInfoPath = '/proc/cpuinfo',
        private readonly string $diskPath = '/',
        private readonly ?string $machineIdOverride = null,
    ) {}

    /**
     * @return array{
     *     hostname: string,
     *     operating_system: string|null,
     *     architecture: string,
     *     cpu_cores: int|null,
     *     total_memory_bytes: int|null,
     *     total_disk_bytes: int|null,
     *     available_disk_bytes: int|null,
     *     addresses: list<string>,
     *     public_ip: string|null,
     *     private_ip: string|null,
     *     docker_version: string|null,
     *     machine_id: string|null
     * }
     */
    public function inspect(): array
    {
        $addresses = $this->addresses();
        $publicIp = null;
        $privateIp = null;

        foreach ($addresses as $address) {
            if ($privateIp === null && self::isPrivateAddress($address)) {
                $privateIp = $address;
            }

            if ($publicIp === null && self::isPublicAddress($address)) {
                $publicIp = $address;
            }
        }

        return [
            'hostname' => $this->hostname(),
            'operating_system' => $this->operatingSystem(),
            'architecture' => self::normalizeArchitecture(php_uname('m')),
            'cpu_cores' => $this->cpuCores(),
            'total_memory_bytes' => $this->totalMemoryBytes(),
            'total_disk_bytes' => $this->diskBytes(disk_total_space(...)),
            'available_disk_bytes' => $this->diskBytes(disk_free_space(...)),
            'addresses' => $addresses,
            'public_ip' => $publicIp,
            'private_ip' => $privateIp,
            'docker_version' => $this->dockerProbe?->version(),
            'machine_id' => $this->machineId(),
        ];
    }

    public function hostname(): string
    {
        $hostname = gethostname();

        if (! is_string($hostname) || trim($hostname) === '') {
            $hostname = php_uname('n');
        }

        return trim($hostname) !== '' ? trim($hostname) : 'localhost';
    }

    public function operatingSystem(): ?string
    {
        $contents = $this->readFile($this->osReleasePath);

        if ($contents !== null) {
            $parsed = self::parseOsRelease($contents);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        $name = trim(php_uname('s'));
        $release = trim(php_uname('r'));

        if ($name === '') {
            return null;
        }

        return $release !== '' ? "{$name} {$release}" : $name;
    }

    public function cpuCores(): ?int
    {
        $contents = $this->readFile($this->cpuInfoPath);

        return $contents === null ? null : self::parseCpuCores($contents);
    }

    public function totalMemoryBytes(): ?int
    {
        $contents = $this->readFile($this->memInfoPath);

        return $contents === null ? null : self::parseMemTotalBytes($contents);
    }

    public function machineId(): ?string
    {
        if ($this->machineIdOverride !== null && trim($this->machineIdOverride) !== '') {
            return self::normalizeMachineId($this->machineIdOverride);
        }

        foreach ([$this->machineIdPath, $this->dbusMachineIdPath] as $path) {
            $contents = $this->readFile($path);

            if ($contents === null) {
                continue;
            }

            $machineId = self::parseMachineId($contents);

            if ($machineId !== null) {
                return $machineId;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function addresses(): array
    {
        if (function_exists('net_get_interfaces')) {
            $interfaces = @net_get_interfaces();

            if (is_array($interfaces)) {
                $addresses = self::parseInterfaces($interfaces);

                if ($addresses !== []) {
                    return $addresses;
                }
            }
        }

        $resolved = @gethostbynamel($this->hostname());

        if (! is_array($resolved)) {
            return [];
        }

        $addresses = [];

        foreach ($resolved as $address) {
            if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            if (self::isLoopbackOrLinkLocal($address)) {
                continue;
            }

            $addresses[$address] = true;
        }

        $addresses = array_keys($addresses);
        sort($addresses);

        return $addresses;
    }

    public static function parseOsRelease(string $contents): ?string
    {
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);

            if ($key === '' || str_starts_with($key, '#')) {
                continue;
            }

            $values[$key] = self::unquoteOsReleaseValue(trim($value));
        }

        $prettyName = trim((string) ($values['PRETTY_NAME'] ?? ''));

        if ($prettyName !== '') {
            return $prettyName;
        }

        $name = trim((string) ($values['NAME'] ?? ''));
        $version = trim((string) ($values['VERSION'] ?? ($values['VERSION_ID'] ?? '')));

        if ($name === '') {
            return null;
        }

        return $version !== '' ? "{$name} {$version}" : $name;
    }

    public static function parseCpuCores(string $contents): ?int
    {
        $count = preg_match_all('/^processor\s*:/mi', $contents);

        return is_int($count) && $count > 0 ? $count : null;
    }

    public static function parseMemTotalBytes(string $contents): ?int
    {
        if (preg_match('/^MemTotal:\s*(\d+)\s*kB/im', $contents, $matches) !== 1) {
            return null;
        }

        $kilobytes = (int) $matches[1];

        return $kilobytes > 0 ? $kilobytes * 1024 : null;
    }

    public static function parseMachineId(string $contents): ?string
    {
        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $machineId = self::normalizeMachineId($line);

            if ($machineId !== null) {
                return $machineId;
            }
        }

        return null;
    }

    public static function normalizeMachineId(string $value): ?string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[a-f0-9]{32}$/', $value) === 1 ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $interfaces
     * @return list<string>
     */
    public static function parseInterfaces(array $interfaces): array
    {
        $addresses = [];

        foreach ($interfaces as $details) {
            if (! is_array($details)) {
                continue;
            }

            if (array_key_exists('up', $details) && $details['up'] === false) {
                continue;
            }

            $unicast = $details['unicast'] ?? [];

            if (! is_array($unicast)) {
                continue;
            }

            foreach ($unicast as $entry) {
                $address = is_array($entry)
                    ? ($entry['address'] ?? null)
                    : (is_string($entry) ? $entry : null);

                if (! is_string($address)) {
                    continue;
                }

                $address = trim($address);

                if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                    continue;
                }

                if (self::isLoopbackOrLinkLocal($address)) {
                    continue;
                }

                $addresses[$address] = true;
            }
        }

        $addresses = array_keys($addresses);
        sort($addresses);

        return $addresses;
    }

    public static function normalizeArchitecture(string $architecture): string
    {
        return match (strtolower(trim($architecture))) {
            'amd64', 'x64', 'x86_64' => 'x86_64',
            'arm64', 'aarch64' => 'aarch64',
            'i386', 'i486', 'i586', 'i686', 'x86' => 'x86',
            default => strtolower(trim($architecture)) !== '' ? strtolower(trim($architecture)) : 'unknown',
        };
    }

    public static function isPrivateAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($address);

            if ($long === false) {
                return false;
            }

            foreach ([['10.0.0.0', '10.255.255.255'], ['172.16.0.0', '172.31.255.255'], ['192.168.0.0', '192.168.255.255'], ['100.64.0.0', '100.127.255.255']] as [$start, $end]) {
                if ($long >= ip2long($start) && $long <= ip2long($end)) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($address);

            if (! is_string($packed) || strlen($packed) !== 16) {
                return false;
            }

            $first = ord($packed[0]);

            return ($first & 0xFE) === 0xFC;
        }

        return false;
    }

    public static function isPublicAddress(string $address): bool
    {
        if (self::isPrivateAddress($address) || self::isLoopbackOrLinkLocal($address)) {
            return false;
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    public static function isLoopbackOrLinkLocal(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($address);

            return $long !== false
                && ($long >= ip2long('127.0.0.0') && $long <= ip2long('127.255.255.255')
                    || $long >= ip2long('169.254.0.0') && $long <= ip2long('169.254.255.255'));
        }

        $packed = inet_pton($address);

        if (! is_string($packed) || strlen($packed) !== 16) {
            return false;
        }

        if ($address === '::1') {
            return true;
        }

        return ord($packed[0]) === 0xFE && (ord($packed[1]) & 0xC0) === 0x80;
    }

    /**
     * @param  callable(string): (float|false)  $probe
     */
    private function diskBytes(callable $probe): ?int
    {
        $bytes = @$probe($this->diskPath);

        return is_float($bytes) || is_int($bytes) ? (int) $bytes : null;
    }

    private function readFile(string $path): ?string
    {
        if (! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    private static function unquoteOsReleaseValue(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        return str_replace(['\\"', '\\\\', '\\$'], ['"', '\\', '$'], $value);
    }
}
