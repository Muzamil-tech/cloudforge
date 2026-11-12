<?php

namespace App\Domains\Operations\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Operations\Enums\AgentCommandStatus;
use App\Domains\Operations\Enums\AgentOperation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'server_id',
    'command_id',
    'operation',
    'payload',
    'status',
    'idempotency_key',
    'attempts',
    'issued_at',
    'expires_at',
    'acknowledged_at',
    'started_at',
    'completed_at',
    'result',
    'error_message',
    'created_by_user_id',
])]
class AgentCommand extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation' => AgentOperation::class,
            'payload' => 'array',
            'status' => AgentCommandStatus::class,
            'result' => 'array',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isDeliverable(): bool
    {
        if ($this->isTerminal() || $this->isExpired()) {
            return false;
        }

        return match ($this->status) {
            AgentCommandStatus::Pending => true,
            AgentCommandStatus::Acknowledged => $this->acknowledged_at === null
                || $this->acknowledged_at->lt(now()->subMinute()),
            default => false,
        };
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeDeliverable(Builder $query): void
    {
        $query->whereIn('status', [
            AgentCommandStatus::Pending->value,
            AgentCommandStatus::Acknowledged->value,
        ])->where('expires_at', '>', now());
    }
}
