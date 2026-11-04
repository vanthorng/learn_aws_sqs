<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $invoice_import_id
 * @property int $source_row_number
 * @property string $status
 * @property array<string, mixed>|null $payload
 * @property array<int, string>|null $errors
 * @property Carbon|null $imported_at
 * @property-read InvoiceImport $invoiceImport
 */
class InvoiceImportRow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'errors' => 'array', 'imported_at' => 'datetime'];
    }

    /** @return BelongsTo<InvoiceImport, $this> */
    public function invoiceImport(): BelongsTo
    {
        return $this->belongsTo(InvoiceImport::class);
    }
}
