<?php

namespace App\Domains\Operations\Services;

use App\Domains\Identity\Models\User;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Models\AuditLog;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        AuditAction $action,
        ?Organization $organization = null,
        ?Model $resource = null,
        array $metadata = [],
        ?User $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();

        return AuditLog::query()->create([
            'organization_id' => $organization?->id,
            'actor_id' => $actor?->id,
            'action' => $action->value,
            'resource_type' => $resource === null ? null : class_basename($resource),
            'resource_id' => $resource?->getKey(),
            'ip_address' => request()->ip(),
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);
    }
}
