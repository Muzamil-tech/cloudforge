<?php

namespace App\Domains\Resources\Presenters;

use App\Domains\Identity\Models\User;
use App\Domains\Resources\Models\Resource;
use Illuminate\Support\Facades\Gate;

class ResourcePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Resource $resource, User $user): array
    {
        $gate = Gate::forUser($user);

        return [
            'id' => $resource->id,
            'name' => $resource->name,
            'type' => $resource->type->value,
            'type_label' => $resource->type->label(),
            'image' => $resource->image,
            'status' => $resource->status->value,
            'status_label' => $resource->status->label(),
            'status_tone' => $resource->status->tone(),
            'project' => [
                'id' => $resource->project->id,
                'name' => $resource->project->name,
            ],
            'environment' => [
                'id' => $resource->environment->id,
                'name' => $resource->environment->name,
                'type' => $resource->environment->type->value,
                'type_label' => $resource->environment->type->label(),
            ],
            'server' => [
                'id' => $resource->server->id,
                'name' => $resource->server->name,
            ],
            'host' => $resource->host,
            'port' => $resource->port,
            'database_name' => $resource->database_name,
            'username' => $resource->username,
            'credentials_revealed' => $resource->isRevealed(),
            'can_reveal' => $resource->canRevealCredentials() && $gate->allows('reveal', $resource),
            'can_start' => $resource->status->canStart() && $gate->allows('start', $resource),
            'can_stop' => $resource->status->canStop() && $gate->allows('stop', $resource),
            'can_delete' => $resource->status->canDelete() && $gate->allows('delete', $resource),
            'can_refresh' => ! $resource->status->isTransitional() && $gate->allows('refresh', $resource),
            'last_error' => $resource->last_error,
            'created_at' => $resource->created_at?->toIso8601String(),
        ];
    }
}
