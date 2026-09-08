<?php

namespace App\Jobs;

use App\Events\InvoiceImportUpdated;
use App\Models\InvoiceImport;
use App\Models\InvoiceRecord;
use App\Services\InvoiceSpreadsheetReader;
use App\Services\QuickBooks\QuickBooksClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncImportToQuickBooks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 3600;

    public function __construct(public readonly string $importId) {}

    public function handle(QuickBooksClient $client): void
    {
        $import = InvoiceImport::with(['team.quickbooksConnection', 'records'])->findOrFail($this->importId);
        $connection = $import->team->quickbooksConnection;

        if (! $connection) {
            $import->update([
                'qbo_sync_status' => 'failed',
                'qbo_sync_error' => 'QuickBooks is not connected for this team.',
            ]);
            event(new InvoiceImportUpdated($import, 'qbo_sync_failed'));
            return;
        }

        $existingSyncedCount = $import->records()->whereNotNull('qbo_invoice_id')->distinct('doc_number')->count('doc_number');
        $records = $import->records()->whereNull('qbo_invoice_id')->orderBy('source_row_number')->get();
        $groupedInvoices = $records->groupBy('doc_number');
        $totalInvoicesToSync = $groupedInvoices->count();
        $totalInvoices = $existingSyncedCount + $totalInvoicesToSync;

        $import->update([
            'qbo_sync_status' => 'syncing',
            'qbo_sync_started_at' => $import->qbo_sync_started_at ?? now(),
            'qbo_sync_error' => null,
            'qbo_total_invoices' => $totalInvoices,
            'qbo_synced_count' => $existingSyncedCount,
            'qbo_failed_count' => 0,
            'qbo_current_message' => "Connecting to QuickBooks Online and preloading catalog...",
        ]);
        $this->safeBroadcast($import, 'qbo_sync_started');

        if ($totalInvoicesToSync === 0) {
            $import->update([
                'qbo_sync_status' => 'synced',
                'qbo_last_synced_at' => now(),
                'qbo_current_message' => "All {$existingSyncedCount} invoices already synced to QuickBooks Online.",
            ]);
            $this->safeBroadcast($import, 'qbo_sync_completed');
            return;
        }

        // Preload existing catalog into memory for 0ms lookups
        $client->preloadEntities($connection);

        $syncedCount = $existingSyncedCount;
        $failedCount = 0;
        $lastError = null;

        // Chunk grouped invoices into batches of 25 (QBO batch limit is 30)
        $batches = $groupedInvoices->chunk(25);
        $totalBatches = $batches->count();
        $batchNumber = 0;

        foreach ($batches as $batch) {
            $batchNumber++;
            $batchPayloads = [];
            $batchDocs = [];

            foreach ($batch as $docNumber => $lines) {
                try {
                    $firstLine = $lines->first();
                    $payload = $firstLine->payload ?? [];

                    // 1. Resolve Customer (in-memory cached)
                    $customerName = $firstLine->customer ?: ($payload['Customer'] ?? 'Valued Customer');
                    $billAddr = [
                        'Line1' => $payload['Bill Addr Line 1'] ?? null,
                        'Line2' => $payload['Bill Addr Line 2'] ?? null,
                        'City' => $payload['Bill Addr City'] ?? null,
                        'CountrySubDivisionCode' => $payload['Bill Addr State'] ?? null,
                        'PostalCode' => $payload['Bill Addr Postal Code'] ?? null,
                        'Country' => $payload['Bill Addr Country'] ?? null,
                    ];
                    $customerId = $client->findOrCreateCustomer($connection, $customerName, $billAddr);

                    // 2. Prepare Line Items (in-memory cached)
                    $qboLines = [];
                    foreach ($lines as $lineRecord) {
                        $row = $lineRecord->payload ?? [];
                        $itemName = $lineRecord->line_item ?: ($row['Line Item'] ?? 'Services');
                        $desc = $row['Line Desc'] ?? $itemName;
                        $qty = (float) ($row['Line Qty'] ?? 1);
                        $unitPrice = (float) ($row['Line Unit Price'] ?? ($lineRecord->line_amount ?: 0));
                        $amount = (float) ($lineRecord->line_amount ?? ($qty * $unitPrice));

                        $itemId = $client->findOrCreateItem($connection, $itemName, $desc, $unitPrice);

                        $lineItemDetail = [
                            'ItemRef' => [
                                'value' => $itemId,
                                'name' => $itemName,
                            ],
                            'UnitPrice' => $unitPrice,
                            'Qty' => $qty,
                        ];

                        $serviceDate = InvoiceSpreadsheetReader::spreadsheetDate($row['Line Service Date'] ?? null);
                        if ($serviceDate) {
                            $lineItemDetail['ServiceDate'] = $serviceDate;
                        }

                        // Attach TaxCodeRef (mandatory for QBO AU GST)
                        $taxCode = $row['Line Tax Code'] ?? null;
                        $taxCodeRef = $client->resolveTaxCodeRef($connection, $taxCode);
                        if ($taxCodeRef) {
                            $lineItemDetail['TaxCodeRef'] = $taxCodeRef;
                        }

                        $qboLines[] = [
                            'Amount' => $amount,
                            'DetailType' => 'SalesItemLineDetail',
                            'Description' => $desc,
                            'SalesItemLineDetail' => $lineItemDetail,
                        ];
                    }

                    // 3. Assemble Invoice Payload
                    $txnDate = $firstLine->txn_date?->toDateString()
                        ?: InvoiceSpreadsheetReader::spreadsheetDate($payload['Txn Date'] ?? null)
                        ?: now()->toDateString();
                    $dueDate = InvoiceSpreadsheetReader::spreadsheetDate($payload['Due Date'] ?? null);

                    $invoicePayload = [
                        'DocNumber' => (string) $docNumber,
                        'TxnDate' => $txnDate,
                        'CustomerRef' => [
                            'value' => $customerId,
                            'name' => $customerName,
                        ],
                        'GlobalTaxCalculation' => ($payload['Amounts Incl Tax'] ?? '') === 'TaxInclusive' ? 'TaxInclusive' : 'TaxExcluded',
                        'Line' => $qboLines,
                    ];

                    if ($dueDate) {
                        $invoicePayload['DueDate'] = $dueDate;
                    }
                    if (! empty($payload['Private Note'])) {
                        $invoicePayload['PrivateNote'] = $payload['Private Note'];
                    }
                    if (! empty($payload['Sales Term'])) {
                        $invoicePayload['SalesTermRef'] = ['name' => $payload['Sales Term']];
                    }
                    if (! empty($payload['Ship Addr Line 1'])) {
                        $invoicePayload['ShipAddr'] = array_filter([
                            'Line1' => $payload['Ship Addr Line 1'],
                            'Line2' => $payload['Ship Addr Line 2'] ?? null,
                            'City' => $payload['Ship Addr City'] ?? null,
                            'CountrySubDivisionCode' => $payload['Ship Addr State'] ?? null,
                            'PostalCode' => $payload['Ship Addr Postal Code'] ?? null,
                            'Country' => $payload['Ship Addr Country'] ?? null,
                        ]);
                    }

                    $batchPayloads[] = [
                        'bId' => (string) $docNumber,
                        'Invoice' => $invoicePayload,
                    ];
                    $batchDocs[(string) $docNumber] = $lines;
                } catch (Throwable $e) {
                    $failedCount++;
                    $lastError = $e->getMessage();
                    Log::warning("Failed to prepare invoice {$docNumber} for QBO batch", ['error' => $e->getMessage()]);
                    InvoiceRecord::whereIn('id', $lines->pluck('id'))->update(['qbo_sync_error' => $e->getMessage()]);
                }
            }

            if (! empty($batchPayloads)) {
                $batchFirstDoc = $batchPayloads[0]['bId'];
                $batchLastDoc = end($batchPayloads)['bId'];

                $import->update([
                    'qbo_current_message' => "Batch {$batchNumber}/{$totalBatches}: Sending {$batchFirstDoc} to {$batchLastDoc} to QuickBooks Online...",
                ]);
                event(new InvoiceImportUpdated($import, 'qbo_sync_progress'));

                try {
                    $results = $client->batchCreateInvoices($connection, $batchPayloads);

                    foreach ($batchPayloads as $item) {
                        $docNumber = $item['bId'];
                        $lines = $batchDocs[$docNumber];
                        $res = $results[$docNumber] ?? null;

                        if ($res && ! empty($res['success'])) {
                            $syncedCount++;
                            InvoiceRecord::whereIn('id', $lines->pluck('id'))->update([
                                'qbo_invoice_id' => $res['invoiceId'],
                                'qbo_synced_at' => now(),
                                'qbo_sync_error' => null,
                            ]);
                        } else {
                            $failedCount++;
                            $err = $res['error'] ?? 'Batch creation failed';
                            $lastError = $err;
                            InvoiceRecord::whereIn('id', $lines->pluck('id'))->update([
                                'qbo_sync_error' => $err,
                            ]);
                        }
                    }
                } catch (Throwable $batchEx) {
                    Log::error("Batch {$batchNumber} execution failed: " . $batchEx->getMessage());
                    foreach ($batchPayloads as $item) {
                        $docNumber = $item['bId'];
                        $lines = $batchDocs[$docNumber];
                        $failedCount++;
                        $lastError = $batchEx->getMessage();
                        InvoiceRecord::whereIn('id', $lines->pluck('id'))->update([
                            'qbo_sync_error' => $batchEx->getMessage(),
                        ]);
                    }
                }
            }

            $currentMsg = "Batch {$batchNumber}/{$totalBatches} completed: {$syncedCount}/{$totalInvoices} invoices pushed to QuickBooks.";
            $import->update([
                'qbo_synced_count' => $syncedCount,
                'qbo_failed_count' => $failedCount,
                'qbo_current_message' => $currentMsg,
            ]);
            $this->safeBroadcast($import, 'qbo_sync_progress');
        }

        $finalStatus = $failedCount > 0 ? ($syncedCount > 0 ? 'partially_synced' : 'failed') : 'synced';
        $startTime = $import->qbo_sync_started_at ?? $import->started_at ?? now();
        $durationSeconds = max(1, now()->diffInSeconds($startTime));
        $durationStr = $durationSeconds >= 60
            ? sprintf('%dm %02ds', intdiv($durationSeconds, 60), $durationSeconds % 60)
            : "{$durationSeconds}s";

        $finalMsg = $failedCount > 0
            ? "Completed in {$durationStr} with notices: {$syncedCount} synced, {$failedCount} failed to QuickBooks Online."
            : "Successfully synced all {$syncedCount} invoices to QuickBooks Online in {$durationStr}!";

        $import->update([
            'qbo_sync_status' => $finalStatus,
            'qbo_last_synced_at' => now(),
            'qbo_current_message' => $finalMsg,
            'qbo_sync_error' => $failedCount > 0 ? "Synced {$syncedCount} invoices, {$failedCount} failed: {$lastError}" : null,
        ]);

        $import->refresh();
        $this->safeBroadcast($import, 'qbo_sync_completed');
    }

    public function failed(Throwable $exception): void
    {
        $import = InvoiceImport::find($this->importId);
        if ($import) {
            $import->update([
                'qbo_sync_status' => 'failed',
                'qbo_sync_error' => 'QuickBooks sync failed: ' . $exception->getMessage(),
            ]);
            $this->safeBroadcast($import, 'qbo_sync_failed');
        }
    }

    private function safeBroadcast(InvoiceImport $import, string $event): void
    {
        try {
            event(new InvoiceImportUpdated($import, $event));
        } catch (\Throwable $e) {
            Log::warning("Broadcasting {$event} failed: " . $e->getMessage());
        }
    }
}
