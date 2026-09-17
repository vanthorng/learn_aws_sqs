<?php

namespace App\Jobs;

use App\Events\QuickbooksOperationUpdated;
use App\Models\QuickbooksOperation;
use App\Notifications\QuickbooksOperationNotification;
use App\Services\QuickBooks\QuickBooksClient;
use App\Services\QuickBooks\QuickbooksExportWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExportQuickbooksInvoices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $operationId) {}

    public function handle(QuickBooksClient $client, QuickbooksExportWriter $writer): void
    {
        $operation = QuickbooksOperation::with(['team.quickbooksConnection', 'requester'])->findOrFail($this->operationId);
        $connection = $operation->team->quickbooksConnection;
        if (! $connection) throw new \RuntimeException('QuickBooks is no longer connected for this team.');

        $operation->update(['status' => 'processing', 'started_at' => now(), 'error_summary' => null]);
        $this->broadcast($operation);
        try {
            $filters = $operation->filters ?? [];
            $all = $client->listInvoices($connection, $filters['from_date'], $filters['to_date']);
            $invoices = $client->filterInvoices($all, $filters);
            $operation->update(['total_count' => count($invoices)]);

            $invoiceRows = [];
            $lineRows = [];
            foreach ($invoices as $index => $invoice) {
                $status = $client->invoiceStatus($invoice);
                $invoiceRows[] = [
                    'Invoice ID' => $invoice['Id'] ?? '', 'Doc Number' => $invoice['DocNumber'] ?? '',
                    'Customer' => $invoice['CustomerRef']['name'] ?? '', 'Transaction Date' => $invoice['TxnDate'] ?? '',
                    'Due Date' => $invoice['DueDate'] ?? '', 'Total' => $invoice['TotalAmt'] ?? '',
                    'Balance' => $invoice['Balance'] ?? '', 'Status' => $status,
                ];
                foreach ($invoice['Line'] ?? [] as $lineNumber => $line) {
                    $detail = $line['SalesItemLineDetail'] ?? [];
                    $lineRows[] = [
                        'Invoice ID' => $invoice['Id'] ?? '', 'Doc Number' => $invoice['DocNumber'] ?? '',
                        'Line Number' => $lineNumber + 1, 'Description' => $line['Description'] ?? '',
                        'Item' => $detail['ItemRef']['name'] ?? '', 'Quantity' => $detail['Qty'] ?? '',
                        'Unit Price' => $detail['UnitPrice'] ?? '', 'Amount' => $line['Amount'] ?? '',
                    ];
                }
                $this->progress($operation, $index + 1, $index + 1, 0);
            }

            $temporaryPath = $writer->write($invoiceRows, $lineRows);
            $disk = config('imports.disk');
            $filename = 'quickbooks-invoices-'.now()->format('Y-m-d-His').'.xlsx';
            $storagePath = 'quickbooks-exports/'.$operation->team_id.'/'.$operation->id.'/'.$filename;
            Storage::disk($disk)->put($storagePath, fopen($temporaryPath, 'rb'));
            @unlink($temporaryPath);
            $operation->update([
                'status' => 'completed', 'processed_count' => count($invoices), 'success_count' => count($invoices), 'percentage' => 100,
                'storage_disk' => $disk, 'storage_path' => $storagePath, 'download_filename' => $filename,
                'completed_at' => now(), 'expires_at' => now()->addDays(7),
            ]);
            $operation->refresh();
            $this->broadcast($operation);
            Notification::send($operation->requester, new QuickbooksOperationNotification($operation));
        } catch (Throwable $exception) {
            $operation->update(['status' => 'failed', 'error_summary' => $exception->getMessage(), 'completed_at' => now()]);
            $operation->refresh();
            $this->broadcast($operation);
            Notification::send($operation->requester, new QuickbooksOperationNotification($operation));
            throw $exception;
        }
    }

    private function progress(QuickbooksOperation $operation, int $processed, int $success, int $failed): void
    {
        $total = max(1, $operation->total_count);
        $operation->update(['processed_count' => $processed, 'success_count' => $success, 'failed_count' => $failed, 'percentage' => min(99, (int) floor($processed / $total * 100))]);
        $operation->refresh();
        $this->broadcast($operation);
    }

    private function broadcast(QuickbooksOperation $operation): void
    {
        try { event(new QuickbooksOperationUpdated($operation)); } catch (Throwable) {}
    }
}
