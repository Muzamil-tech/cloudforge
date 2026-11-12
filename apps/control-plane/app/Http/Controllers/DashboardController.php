<?php

namespace App\Http\Controllers;

use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, OrganizationContext $organizationContext): Response
    {
        $organization = $organizationContext->require($request);

        $servers = $organization->servers()
            ->with('roles')
            ->orderByDesc('last_heartbeat_at')
            ->get();

        $statusCounts = collect(ServerStatus::cases())
            ->mapWithKeys(fn (ServerStatus $status): array => [
                $status->value => $servers->where('status', $status)->count(),
            ])
            ->all();

        return Inertia::render('Dashboard', [
            'stats' => [
                'servers' => $servers->count(),
                'healthy' => $servers->filter(fn ($server): bool => $server->status->isReachable())->count(),
                'projects' => $organization->projects()->count(),
                'members' => $organization->memberships()->where('status', 'active')->count(),
            ],
            'statusCounts' => $statusCounts,
            'servers' => $servers->take(8)->map(fn ($server): array => [
                'id' => $server->id,
                'name' => $server->name,
                'hostname' => $server->hostname,
                'status' => $server->status->value,
                'status_label' => $server->status->label(),
                'tone' => $server->status->tone(),
                'roles' => $server->roleNames(),
                'last_heartbeat_at' => $server->last_heartbeat_at?->toIso8601String(),
                'docker_version' => $server->docker_version,
                'agent_version' => $server->agent_version,
            ])->all(),
            'recentAudit' => $organization->auditLogs()
                ->with('actor')
                ->orderByDesc('created_at')
                ->limit(8)
                ->get()
                ->map(fn ($log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'actor' => $log->actor?->name ?? 'System',
                    'resource_type' => $log->resource_type,
                    'resource_id' => $log->resource_id,
                    'created_at' => $log->created_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }
}
