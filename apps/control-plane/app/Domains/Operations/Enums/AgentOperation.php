<?php

namespace App\Domains\Operations\Enums;

enum AgentOperation: string
{
    case ServerInspect = 'server.inspect';
    case ServerHealth = 'server.health';
    case MetricsCollect = 'metrics.collect';
    case AgentRestart = 'agent.restart';

    public function label(): string
    {
        return match ($this) {
            self::ServerInspect => 'Inspect server',
            self::ServerHealth => 'Check server health',
            self::MetricsCollect => 'Collect metrics',
            self::AgentRestart => 'Restart agent',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ServerInspect => 'Collect operating system, Docker and runtime inventory from the agent.',
            self::ServerHealth => 'Verify the agent and Docker daemon are reachable and report a health snapshot.',
            self::MetricsCollect => 'Sample CPU, memory, disk and network counters from the host.',
            self::AgentRestart => 'Ask the agent to exit so the supervisor can restart it. The result is best-effort.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadRules(): array
    {
        return match ($this) {
            self::ServerInspect, self::ServerHealth, self::MetricsCollect, self::AgentRestart => [
                'reason' => ['nullable', 'string', 'max:255'],
            ],
        };
    }

    /**
     * Commands that mutate the host or interrupt the agent require an explicit
     * operational role. Read-only commands are also allowed for developers.
     */
    public function requiresOperationalRole(): bool
    {
        return $this === self::AgentRestart;
    }

    public function ttlSeconds(): int
    {
        return match ($this) {
            self::AgentRestart => 60,
            default => (int) config('cloudforge.agent.command_ttl_seconds', 300),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
