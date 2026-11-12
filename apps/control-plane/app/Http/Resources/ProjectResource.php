<?php

namespace App\Http\Resources;

use App\Domains\Projects\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'environments' => $this->whenLoaded('environments', fn () => $this->environments->map(fn ($environment) => [
                'id' => $environment->id,
                'name' => $environment->name,
                'slug' => $environment->slug,
                'type' => $environment->type->value,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
