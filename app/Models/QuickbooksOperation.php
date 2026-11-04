<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $team_id
 * @property int $requested_by
 * @property string $type
 * @property string $status
 * @property array<string, mixed>|null $filters
 * @property int $total_count
 * @property int $processed_count
 * @property int $success_count
 * @property int $failed_count
 * @property int $percentage
 * @property string|null $storage_disk
 * @property string|null $storage_path
 * @property string|null $download_filename
 * @property string|null $error_summary
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property-read Team $team
 * @property-read User $requester
 */
class QuickbooksOperation extends Model
{
    use HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return HasMany<QuickbooksOperationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(QuickbooksOperationItem::class);
    }
}
