<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class InvoiceRecord extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['txn_date' => 'date', 'line_amount' => 'decimal:4', 'payload' => 'array', 'qbo_synced_at' => 'datetime']; }
    public function invoiceImport(): BelongsTo { return $this->belongsTo(InvoiceImport::class); }
}
