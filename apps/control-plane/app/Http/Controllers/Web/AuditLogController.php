<?php

namespace App\Http\Controllers\Web;

use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request, OrganizationContext $organizationContext): Response
    {
        $organization = $organizationContext->require($request);
        Gate::authorize('viewAudit', $organization);

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:120'],
        ]);

        $logs = $organization->auditLogs()
            ->with('actor')
            ->when(
                isset($filters['action']) && $filters['action'] !== '',
                fn ($query) => $query->where('action', $filters['action']),
            )
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'action_label' => Str::headline(str_replace(['.', '_'], ' ', $log->action)),
                'actor' => $log->actor?->name ?? 'System',
                'resource_type' => $log->resource_type,
                'resource_id' => $log->resource_id,
                'ip_address' => $log->ip_address,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('AuditLog/Index', [
            'logs' => $logs,
            'filters' => [
                'action' => $filters['action'] ?? '',
            ],
            'actions' => collect(AuditAction::cases())
                ->map(fn (AuditAction $action): array => [
                    'value' => $action->value,
                    'label' => Str::headline(str_replace(['.', '_'], ' ', $action->value)),
                ])
                ->all(),
        ]);
    }
}
