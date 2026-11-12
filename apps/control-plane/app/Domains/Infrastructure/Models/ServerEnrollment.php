<?php

namespace App\Domains\Infrastructure\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Enums\EnrollmentPurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['organization_id', 'server_id', 'token_hash', 'purpose', 'expires_at', 'consumed_at', 'created_by_user_id'])]
class ServerEnrollment extends Model
{
    use HasUuids;

    protected $table = 'server_enrollments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => EnrollmentPurpose::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
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

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isConsumed() && ! $this->isExpired();
    }

    public function isBootstrap(): bool
    {
        return $this->purpose === EnrollmentPurpose::Bootstrap;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }
}
