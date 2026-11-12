<?php

namespace App\Domains\Infrastructure\Services;

use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerMetric;
use Carbon\CarbonImmutable;

class RecordMetrics
{
    /**
     * @param  list<array<string, mixed>>  $points
     */
    public function execute(Server $server, array $points): int
    {
        $limit = (int) config('cloudforge.metrics.max_points_per_request', 240);
        $points = array_slice($points, 0, $limit);

        if ($points === []) {
            return 0;
        }

        $now = now();
        $rows = [];

        foreach ($points as $point) {
            $rows[] = [
                'server_id' => $server->id,
                'recorded_at' => CarbonImmutable::parse($point['recorded_at'])->utc(),
                'cpu_usage' => $point['cpu_usage'] ?? null,
                'memory_usage' => $point['memory_usage'] ?? null,
                'disk_usage' => $point['disk_usage'] ?? null,
                'memory_used_bytes' => $point['memory_used_bytes'] ?? null,
                'disk_used_bytes' => $point['disk_used_bytes'] ?? null,
                'network_in_bytes' => $point['network_in_bytes'] ?? null,
                'network_out_bytes' => $point['network_out_bytes'] ?? null,
                'metadata' => isset($point['metadata']) && is_array($point['metadata'])
                    ? json_encode($point['metadata'], JSON_THROW_ON_ERROR)
                    : null,
            ];
        }

        ServerMetric::query()->insert($rows);

        $server->forceFill(['last_heartbeat_at' => $now])->save();

        return count($rows);
    }
}
