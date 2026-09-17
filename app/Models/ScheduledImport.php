<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoiceImport(): BelongsTo
    {
        return $this->belongsTo(InvoiceImport::class);
    }
}
