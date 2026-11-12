<?php

namespace App\Http\Resources;

use App\Domains\Operations\Models\AgentCommand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentCommand
 */
class AgentCommandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->command_id,
            'operation' => $this->operation->value,
            'operation_label' => $this->operation->label(),
            'payload' => $this->payload ?? [],
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'attempts' => $this->attempts,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'result' => $this->result,
            'error_message' => $this->error_message,
        ];
    }
}
