<?php

namespace App\Domains\Operations\Enums;

enum AgentOperation: string
{
    case ServerInspect = 'server.inspect';
    case ServerHealth = 'server.health';
    case MetricsCollect = 'metrics.collect';
    case AgentRestart = 'agent.restart';
    case ResourceCreate = 'resource.create';
    case ResourceStart = 'resource.start';
    case ResourceStop = 'resource.stop';
    case ResourceDelete = 'resource.delete';
    case ResourceInspect = 'resource.inspect';

    public function label(): string
    {
        return match ($this) {
            self::ServerInspect => 'Inspect server',
            self::ServerHealth => 'Check server health',
            self::MetricsCollect => 'Collect metrics',
            self::AgentRestart => 'Restart agent',
            self::ResourceCreate => 'Provision resource',
            self::ResourceStart => 'Start resource',
            self::ResourceStop => 'Stop resource',
            self::ResourceDelete => 'Delete resource',
            self::ResourceInspect => 'Inspect resource',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ServerInspect => 'Collect operating system, Docker and runtime inventory from the agent.',
            self::ServerHealth => 'Verify the agent and Docker daemon are reachable and report a health snapshot.',
            self::MetricsCollect => 'Sample CPU, memory, disk and network counters from the host.',
            self::AgentRestart => 'Ask the agent to exit so the supervisor can restart it. The result is best-effort.',
            self::ResourceCreate => 'Create and start a managed PostgreSQL or Redis container.',
            self::ResourceStart => 'Start an existing managed resource container.',
            self::ResourceStop => 'Stop an existing managed resource container.',
            self::ResourceDelete => 'Remove a managed resource container and its persistent volume.',
            self::ResourceInspect => 'Read the current state of a managed resource container.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadRules(): array
    {
        $nameRules = ['required', 'string', 'max:63', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'];

        return match ($this) {
            self::ServerInspect, self::ServerHealth, self::MetricsCollect, self::AgentRestart => [
                'reason' => ['nullable', 'string', 'max:255'],
            ],
            self::ResourceCreate => [
                'resource_id' => ['required', 'uuid'],
                'resource_type' => ['required', 'string', 'in:postgresql,redis'],
                'container_name' => $nameRules,
                'volume_name' => $nameRules,
                'reason' => ['nullable', 'string', 'max:255'],
            ],
            self::ResourceStart, self::ResourceStop, self::ResourceInspect => [
                'resource_id' => ['required', 'uuid'],
                'container_name' => $nameRules,
                'reason' => ['nullable', 'string', 'max:255'],
            ],
            self::ResourceDelete => [
                'resource_id' => ['required', 'uuid'],
                'container_name' => $nameRules,
                'volume_name' => $nameRules,
                'reason' => ['nullable', 'string', 'max:255'],
            ],
        };
    }

    public function isResourceOperation(): bool
    {
        return in_array($this, [
            self::ResourceCreate,
            self::ResourceStart,
            self::ResourceStop,
            self::ResourceDelete,
            self::ResourceInspect,
        ], true);
    }

    /**
     * Commands that mutate the host or interrupt the agent require an explicit
     * operational role. Read-only commands are also allowed for developers.
     */
    public function requiresOperationalRole(): bool
    {
        return in_array($this, [self::AgentRestart, self::ResourceDelete], true);
    }

    public function ttlSeconds(): int
    {
        return match ($this) {
            self::AgentRestart => 60,
            self::ResourceCreate => 900,
            self::ResourceStart, self::ResourceStop => 120,
            self::ResourceDelete => 180,
            self::ResourceInspect => 60,
            default => (int) config('cloudforge.agent.command_ttl_seconds', 300),
        };
    }

    /**
     * Operations exposed through the generic server command API and UI. Resource
     * operations are issued only by the resource domain, with their own
     * ownership checks.
     *
     * @return list<string>
     */
    public static function serverValues(): array
    {
        return [
            self::ServerInspect->value,
            self::ServerHealth->value,
            self::MetricsCollect->value,
            self::AgentRestart->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
