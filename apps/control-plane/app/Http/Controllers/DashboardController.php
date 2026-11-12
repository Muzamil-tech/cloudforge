<?php

namespace App\Http\Controllers;

use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Resources\Enums\ResourceStatus;
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

        $resources = $organization->resources()
            ->with(['project', 'environment', 'server'])
            ->latest()
            ->get();

        $failedResources = $resources->where('status', ResourceStatus::Failed);
        $attention = collect();

        $servers
            ->filter(fn ($server): bool => in_array($server->status, [ServerStatus::Unreachable, ServerStatus::Error], true))
            ->each(function ($server) use ($attention): void {
                $attention->push([
                    'id' => 'server-'.$server->id,
                    'tone' => $server->status === ServerStatus::Error ? 'danger' : 'warning',
                    'title' => "{$server->name} is {$server->status->label()}",
                    'description' => $server->last_heartbeat_at === null
                        ? 'The agent has not reported a heartbeat yet.'
                        : 'Last heartbeat '.$server->last_heartbeat_at->diffForHumans().'.',
                    'href' => "/servers/{$server->id}",
                ]);
            });

        $failedResources->take(5)->each(function ($resource) use ($attention): void {
            $attention->push([
                'id' => 'resource-'.$resource->id,
                'tone' => 'danger',
                'title' => "{$resource->name} failed",
                'description' => $resource->last_error ?: 'The managed resource is not healthy.',
                'href' => "/projects/{$resource->project_id}",
            ]);
        });

        return Inertia::render('Dashboard', [
            'stats' => [
                'servers' => $servers->count(),
                'healthy' => $servers->filter(fn ($server): bool => $server->status->isReachable())->count(),
                'projects' => $organization->projects()->count(),
                'databases' => $resources->count(),
                'members' => $organization->memberships()->where('status', 'active')->count(),
            ],
            'resourceStats' => [
                'active' => $resources->where('status', ResourceStatus::Active)->count(),
                'failed' => $failedResources->count(),
                'provisioning' => $resources->where('status', ResourceStatus::Provisioning)->count(),
            ],
            'attention' => $attention->take(6)->values()->all(),
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
            'resources' => $resources->take(5)->map(fn ($resource): array => [
                'id' => $resource->id,
                'name' => $resource->name,
                'type' => $resource->type->value,
                'type_label' => $resource->type->label(),
                'status' => $resource->status->value,
                'status_label' => $resource->status->label(),
                'status_tone' => $resource->status->tone(),
                'project' => [
                    'id' => $resource->project->id,
                    'name' => $resource->project->name,
                ],
                'environment' => $resource->environment->name,
                'server' => $resource->server->name,
                'port' => $resource->port,
                'last_error' => $resource->last_error,
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
