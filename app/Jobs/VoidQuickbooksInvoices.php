<?php

namespace App\Jobs;

use App\Events\QuickbooksOperationUpdated;
use App\Models\InvoiceRecord;
use App\Models\QuickbooksOperation;
use App\Models\QuickbooksOperationItem;
use App\Notifications\QuickbooksOperationNotification;
use App\Services\QuickBooks\QuickBooksClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

class VoidQuickbooksInvoices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $operationId) {}

    public function handle(QuickBooksClient $client): void
    {
        $operation = QuickbooksOperation::with(['team.quickbooksConnection', 'requester', 'items'])->findOrFail($this->operationId);
        $connection = $operation->team->quickbooksConnection;
        if (! $connection) throw new \RuntimeException('QuickBooks is no longer connected for this team.');

        $operation->update(['status' => 'processing', 'started_at' => now(), 'error_summary' => null]);
        $this->broadcast($operation);
        foreach ($operation->items as $index => $item) {
            try {
                $invoice = $client->voidInvoice($connection, $item->qbo_invoice_id);
                InvoiceRecord::query()->where('qbo_invoice_id', $item->qbo_invoice_id)
                    ->whereHas('invoiceImport', fn ($query) => $query->where('team_id', $operation->team_id))
                    ->update(['qbo_voided_at' => now(), 'qbo_void_error' => null]);
                $item->update(['status' => 'completed', 'doc_number' => $invoice['DocNumber'] ?? null, 'customer' => $invoice['CustomerRef']['name'] ?? null, 'error_summary' => null]);
                $this->progress($operation, $index + 1, 1, 0);
            } catch (Throwable $exception) {
                $item->update(['status' => 'failed', 'error_summary' => $exception->getMessage()]);
                InvoiceRecord::query()->where('qbo_invoice_id', $item->qbo_invoice_id)
                    ->whereHas('invoiceImport', fn ($query) => $query->where('team_id', $operation->team_id))
                    ->update(['qbo_void_error' => $exception->getMessage()]);
                $this->progress($operation, $index + 1, 0, 1);
            }
        }

        $operation->refresh();
        $operation->update(['status' => $operation->failed_count > 0 ? 'completed_with_errors' : 'completed', 'percentage' => 100, 'completed_at' => now()]);
        $operation->refresh();
        $this->broadcast($operation);
        Notification::send($operation->requester, new QuickbooksOperationNotification($operation));
    }

    private function progress(QuickbooksOperation $operation, int $processed, int $successIncrement, int $failedIncrement): void
    {
        $operation->increment('success_count', $successIncrement);
        $operation->increment('failed_count', $failedIncrement);
        $operation->update(['processed_count' => $processed, 'percentage' => min(99, (int) floor($processed / max(1, $operation->total_count) * 100))]);
        $operation->refresh();
        $this->broadcast($operation);
    }

    private function broadcast(QuickbooksOperation $operation): void
    {
        try { event(new QuickbooksOperationUpdated($operation)); } catch (Throwable) {}
    }
}
