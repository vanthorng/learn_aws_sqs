<?php

namespace App\Jobs;

use App\Enums\InvoiceImportStatus;
use App\Events\InvoiceImportUpdated;
use App\Models\InvoiceImport;
use App\Models\InvoiceImportRow;
use App\Models\InvoiceRecord;
use App\Services\InvoiceSpreadsheetReader;
use Aws\Sqs\SqsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessInvoiceImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly string $importId) {}

    public function handle(): void
    {
        $claimed = InvoiceImport::query()
            ->whereKey($this->importId)
            ->where('status', InvoiceImportStatus::Pending->value)
            ->update([
                'status' => InvoiceImportStatus::Processing->value,
                'started_at' => now(),
                'attempt_count' => DB::raw('attempt_count + 1'),
                'error_summary' => null,
            ]);

        if ($claimed !== 1) {
            return;
        }

        $import = InvoiceImport::findOrFail($this->importId);
        event(new InvoiceImportUpdated($import, 'started'));
        $temporaryPath = null;

        try {
            $path = $this->localPath($import, $temporaryPath);
            $reader = new InvoiceSpreadsheetReader($path);
            $total = $reader->assertValidTemplate();
            $import->update(['total_rows' => $total, 'processed_rows' => 0, 'success_count' => 0, 'failed_count' => 0, 'percentage' => 0]);

            $batch = [];
            foreach ($reader->dataRows() as $row) {
                $batch[] = $row;
                if (count($batch) >= config('imports.batch_size')) {
                    $this->persistBatch($import, $batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->persistBatch($import, $batch);
            }

            $import->refresh();
            $import->update(['status' => InvoiceImportStatus::Completed, 'percentage' => 100, 'completed_at' => now()]);
            $import->refresh();
            event(new InvoiceImportUpdated($import, 'completed'));
        } catch (Throwable $exception) {
            $import->update(['status' => InvoiceImportStatus::Pending->value]);
            Log::warning('Invoice import attempt failed.', ['import_id' => $import->id, 'team_id' => $import->team_id, 'exception' => $exception]);
            throw $exception;
        } finally {
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        $import = InvoiceImport::find($this->importId);
        if (! $import || $import->status === InvoiceImportStatus::Completed) {
            return;
        }

        $import->update([
            'status' => InvoiceImportStatus::Failed,
            'completed_at' => now(),
            'error_summary' => 'The import could not be processed. Please retry or contact our support.',
        ]);
        $import->refresh();
        Log::error('Invoice import failed permanently.', ['import_id' => $import->id, 'team_id' => $import->team_id, 'exception' => $exception]);
        $this->sendToDeadLetterQueue($import, $exception);
        event(new InvoiceImportUpdated($import, 'failed'));
    }

    /** @param list<array{rowNumber: int, values: array<string, string|null>}> $batch */
    private function persistBatch(InvoiceImport $import, array $batch): void
    {
        DB::transaction(function () use ($import, $batch): void {
            foreach ($batch as $row) {
                $errors = $this->validationErrors($row['values']);
                $rowData = [
                    'status' => $errors === [] ? 'imported' : 'failed',
                    'payload' => $row['values'],
                    'errors' => $errors === [] ? null : $errors,
                    'imported_at' => $errors === [] ? now() : null,
                ];
                InvoiceImportRow::updateOrCreate([
                    'invoice_import_id' => $import->id,
                    'source_row_number' => $row['rowNumber'],
                ], $rowData);

                if ($errors === []) {
                    InvoiceRecord::updateOrCreate([
                        'invoice_import_id' => $import->id,
                        'source_row_number' => $row['rowNumber'],
                    ], [
                        'doc_number' => $row['values']['Doc Number'],
                        'customer' => $row['values']['Customer'],
                        'txn_date' => InvoiceSpreadsheetReader::spreadsheetDate($row['values']['Txn Date']),
                        'line_item' => $row['values']['Line Item'],
                        'line_amount' => InvoiceSpreadsheetReader::numericValue($row['values']['Line Amount']),
                        'payload' => $row['values'],
                    ]);
                }
            }

            $processed = InvoiceImportRow::query()->where('invoice_import_id', $import->id);
            $total = $import->total_rows;
            $processedCount = (clone $processed)->count();
            $success = (clone $processed)->where('status', 'imported')->count();
            $failed = $processedCount - $success;
            $import->update([
                'processed_rows' => $processedCount,
                'success_count' => $success,
                'failed_count' => $failed,
                'percentage' => $total === 0 ? 100 : min(99, (int) floor($processedCount / $total * 100)),
            ]);
        });

        $import->refresh();
        event(new InvoiceImportUpdated($import, 'progress'));
    }

    /** @param array<string, string|null> $values
     *  @return array<string, string> */
    private function validationErrors(array $values): array
    {
        $errors = [];
        foreach (['Doc Number', 'Customer', 'Txn Date', 'Line Item'] as $field) {
            if (blank($values[$field] ?? null)) {
                $errors[$field] = 'This field is required.';
            }
        }
        foreach (['Txn Date', 'Due Date', 'Ship Date', 'Line Service Date'] as $field) {
            if (filled($values[$field] ?? null) && InvoiceSpreadsheetReader::spreadsheetDate($values[$field]) === null) {
                $errors[$field] = 'Enter a valid date.';
            }
        }
        foreach (['Exchange Rate', 'Deposit', 'Ship Amt', 'Discount Amt', 'Discount Rate', 'Line Qty', 'Line Unit Price', 'Line Amount'] as $field) {
            if (filled($values[$field] ?? null) && InvoiceSpreadsheetReader::numericValue($values[$field]) === null) {
                $errors[$field] = 'Enter a numeric value.';
            }
        }

        return $errors;
    }

    private function localPath(InvoiceImport $import, ?string &$temporaryPath): string
    {
        $disk = Storage::disk($import->storage_disk);
        if ($import->storage_disk === 'local') {
            return $disk->path($import->storage_path);
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'invoice-import-');
        $source = $disk->readStream($import->storage_path);
        $destination = fopen($temporaryPath, 'wb');
        stream_copy_to_stream($source, $destination);
        fclose($destination);
        fclose($source);

        return $temporaryPath;
    }

    private function sendToDeadLetterQueue(InvoiceImport $import, Throwable $exception): void
    {
        $queue = config('imports.dead_letter_queue');
        if (blank($queue) || config('imports.queue_connection') !== 'sqs') {
            return;
        }

        try {
            $queueUrl = str_starts_with($queue, 'http')
                ? $queue
                : rtrim((string) config('queue.connections.sqs.prefix'), '/').'/'.$queue.config('queue.connections.sqs.suffix');
            $clientConfig = [
                'version' => 'latest',
                'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            ];
            if (filled(env('AWS_ACCESS_KEY_ID'))) {
                $clientConfig['credentials'] = ['key' => env('AWS_ACCESS_KEY_ID'), 'secret' => env('AWS_SECRET_ACCESS_KEY')];
            }
            (new SqsClient($clientConfig))->sendMessage([
                'QueueUrl' => $queueUrl,
                'MessageBody' => json_encode([
                    'import_id' => $import->id,
                    'team_id' => $import->team_id,
                    'attempt_count' => $import->attempt_count,
                    'failed_at' => now()->toIso8601String(),
                    'error' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $deadLetterException) {
            Log::warning('Could not publish invoice import failure to the SQS DLQ.', [
                'import_id' => $import->id,
                'exception' => $deadLetterException,
            ]);
        }
    }
}
