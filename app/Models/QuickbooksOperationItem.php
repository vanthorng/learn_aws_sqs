<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickbooksOperationItem extends Model
{
    protected $guarded = [];

    public function operation(): BelongsTo { return $this->belongsTo(QuickbooksOperation::class, 'quickbooks_operation_id'); }
}
