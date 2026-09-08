<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class InvoiceImportRow extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['payload' => 'array', 'errors' => 'array', 'imported_at' => 'datetime']; }
    public function invoiceImport(): BelongsTo { return $this->belongsTo(InvoiceImport::class); }
}
