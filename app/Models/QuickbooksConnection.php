<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $realm_id
 * @property string $access_token
 * @property string $refresh_token
 * @property Carbon $access_token_expires_at
 * @property Carbon $refresh_token_expires_at
 * @property string|null $company_name
 * @property string $environment
 * @property-read Team $team
 */
class QuickbooksConnection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isAccessTokenExpired(): bool
    {
        return $this->access_token_expires_at->isPast();
    }

    public function isRefreshTokenExpired(): bool
    {
        return $this->refresh_token_expires_at->isPast();
    }
}
