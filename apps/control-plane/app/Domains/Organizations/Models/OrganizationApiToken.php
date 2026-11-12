<?php

namespace App\Domains\Organizations\Models;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'name', 'token_hash', 'abilities', 'last_used_at', 'expires_at', 'created_by_user_id'])]
#[Hidden(['token_hash'])]
class OrganizationApiToken extends Model
{
    use HasUuids;

    protected $table = 'organization_api_tokens';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function can(string $ability): bool
    {
        foreach ($this->abilities ?? [] as $granted) {
            if ($granted === '*' || $granted === $ability) {
                return true;
            }

            if (str_ends_with($granted, '*') && str_starts_with($ability, rtrim($granted, '*'))) {
                return true;
            }
        }

        return false;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
