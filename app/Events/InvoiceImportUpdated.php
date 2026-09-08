<?php

namespace App\Events;

use App\Models\InvoiceImport;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoiceImportUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public InvoiceImport $invoiceImport, private readonly string $event) {}

    public function broadcastOn(): array { return [new PrivateChannel('import.'.$this->invoiceImport->id)]; }
    public function broadcastAs(): string { return 'import.'.$this->event; }
    public function broadcastWith(): array
    {
        return [
            'importId' => $this->invoiceImport->id,
            'status' => $this->invoiceImport->status->value,
            'processedRows' => $this->invoiceImport->processed_rows,
            'totalRows' => $this->invoiceImport->total_rows,
            'successCount' => $this->invoiceImport->success_count,
            'failedCount' => $this->invoiceImport->failed_count,
            'percentage' => $this->invoiceImport->percentage,
            'errorSummary' => $this->invoiceImport->error_summary,
        ];
    }
}
