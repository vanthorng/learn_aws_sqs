<?php

namespace App\Models;

use App\Enums\InvoiceImportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $team_id
 * @property int $uploaded_by
 * @property InvoiceImportStatus $status
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $original_filename
 * @property string $file_hash
 * @property int $file_size
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $success_count
 * @property int $failed_count
 * @property int $percentage
 * @property int $attempt_count
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property string|null $error_summary
 * @property string $qbo_sync_status
 * @property bool $auto_sync_qbo
 * @property Carbon|null $qbo_sync_started_at
 * @property int $qbo_synced_count
 * @property int $qbo_failed_count
 * @property int $qbo_total_invoices
 * @property string|null $qbo_current_message
 * @property Carbon|null $qbo_last_synced_at
 * @property string|null $qbo_sync_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User $uploader
 */
class InvoiceImport extends Model
{
    use HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => InvoiceImportStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'qbo_sync_started_at' => 'datetime',
            'qbo_last_synced_at' => 'datetime',
            'auto_sync_qbo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<InvoiceImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(InvoiceImportRow::class);
    }

    /** @return HasMany<InvoiceRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(InvoiceRecord::class);
    }
}
