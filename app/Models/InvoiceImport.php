<?php

namespace App\Models;

use App\Enums\InvoiceImportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

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
        ];
    }

    public function team(): BelongsTo { return $this->belongsTo(Team::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function rows(): HasMany { return $this->hasMany(InvoiceImportRow::class); }
    public function records(): HasMany { return $this->hasMany(InvoiceRecord::class); }
}
