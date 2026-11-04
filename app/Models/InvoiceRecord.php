<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $invoice_import_id
 * @property int $source_row_number
 * @property string $doc_number
 * @property string $customer
 * @property Carbon $txn_date
 * @property string $line_item
 * @property string|null $line_amount
 * @property array<string, mixed> $payload
 * @property string|null $qbo_invoice_id
 * @property Carbon|null $qbo_synced_at
 * @property string|null $qbo_sync_error
 * @property Carbon|null $qbo_voided_at
 * @property string|null $qbo_deleted_at
 * @property-read InvoiceImport $invoiceImport
 */
class InvoiceRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['txn_date' => 'date', 'line_amount' => 'decimal:4', 'payload' => 'array', 'qbo_synced_at' => 'datetime', 'qbo_voided_at' => 'datetime', 'qbo_deleted_at' => 'datetime'];
    }

    /** @return BelongsTo<InvoiceImport, $this> */
    public function invoiceImport(): BelongsTo
    {
        return $this->belongsTo(InvoiceImport::class);
    }
}
