<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $team_id
 * @property int $created_by
 * @property string|null $invoice_import_id
 * @property string $status
 * @property Carbon $scheduled_for
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $original_filename
 * @property string $file_hash
 * @property int $file_size
 * @property bool $auto_sync_qbo
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $cancelled_at
 * @property-read Team $team
 * @property-read User $creator
 * @property-read InvoiceImport|null $invoiceImport
 */
class ScheduledImport extends Model
{
    use HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'dispatched_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'auto_sync_qbo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<InvoiceImport, $this> */
    public function invoiceImport(): BelongsTo
    {
        return $this->belongsTo(InvoiceImport::class);
    }
}
