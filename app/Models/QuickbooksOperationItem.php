<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $quickbooks_operation_id
 * @property string $qbo_invoice_id
 * @property string|null $doc_number
 * @property string|null $customer
 * @property string $status
 * @property string|null $error_summary
 * @property-read QuickbooksOperation $operation
 */
class QuickbooksOperationItem extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<QuickbooksOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(QuickbooksOperation::class, 'quickbooks_operation_id');
    }
}
