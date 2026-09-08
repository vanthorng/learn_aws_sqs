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
        $parseDuration = null;
        if ($this->invoiceImport->started_at && $this->invoiceImport->completed_at) {
            $diff = max(1, $this->invoiceImport->completed_at->diffInSeconds($this->invoiceImport->started_at));
            $parseDuration = $diff >= 60 ? sprintf('%dm %02ds', intdiv($diff, 60), $diff % 60) : "{$diff}s";
        }

        $qboSyncDuration = null;
        $syncStart = $this->invoiceImport->qbo_sync_started_at ?? $this->invoiceImport->completed_at ?? $this->invoiceImport->started_at;
        if ($syncStart && $this->invoiceImport->qbo_last_synced_at) {
            $diff = max(1, $this->invoiceImport->qbo_last_synced_at->diffInSeconds($syncStart));
            $qboSyncDuration = $diff >= 60 ? sprintf('%dm %02ds', intdiv($diff, 60), $diff % 60) : "{$diff}s";
        }

        $totalDuration = null;
        $finalEnd = $this->invoiceImport->qbo_last_synced_at ?? $this->invoiceImport->completed_at;
        if ($this->invoiceImport->started_at && $finalEnd) {
            $diff = max(1, $finalEnd->diffInSeconds($this->invoiceImport->started_at));
            $totalDuration = $diff >= 60 ? sprintf('%dm %02ds', intdiv($diff, 60), $diff % 60) : "{$diff}s";
        }

        return [
            'importId' => $this->invoiceImport->id,
            'status' => $this->invoiceImport->status->value,
            'processedRows' => $this->invoiceImport->processed_rows,
            'totalRows' => $this->invoiceImport->total_rows,
            'successCount' => $this->invoiceImport->success_count,
            'failedCount' => $this->invoiceImport->failed_count,
            'percentage' => $this->invoiceImport->percentage,
            'errorSummary' => $this->invoiceImport->error_summary,
            'qboSyncStatus' => $this->invoiceImport->qbo_sync_status ?? 'not_synced',
            'qboSyncedCount' => $this->invoiceImport->qbo_synced_count ?? 0,
            'qboFailedCount' => $this->invoiceImport->qbo_failed_count ?? 0,
            'qboTotalInvoices' => $this->invoiceImport->qbo_total_invoices ?? 0,
            'qboCurrentMessage' => $this->invoiceImport->qbo_current_message,
            'qboSyncError' => $this->invoiceImport->qbo_sync_error,
            'parseDuration' => $parseDuration,
            'qboSyncDuration' => $qboSyncDuration,
            'totalDuration' => $totalDuration,
        ];
    }
}
