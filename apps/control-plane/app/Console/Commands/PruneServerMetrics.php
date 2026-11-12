<?php

namespace App\Console\Commands;

use App\Domains\Infrastructure\Models\ServerMetric;
use Illuminate\Console\Command;

class PruneServerMetrics extends Command
{
    protected $signature = 'metrics:prune
                            {--days= : Retention window in days (defaults to configuration)}';

    protected $description = 'Delete server metrics older than the configured retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('cloudforge.metrics.retention_days', 14));

        if ($days < 1) {
            $this->error('Retention must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = ServerMetric::query()->where('recorded_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} metric row(s) recorded before {$cutoff->toIso8601String()}.");

        return self::SUCCESS;
    }
}
