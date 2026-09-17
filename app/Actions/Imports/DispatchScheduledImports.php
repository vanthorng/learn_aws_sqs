<?php

namespace App\Actions\Imports;

use App\Enums\InvoiceImportStatus;
use App\Jobs\ProcessInvoiceImport;
use App\Models\InvoiceImport;
use App\Models\ScheduledImport;
use Illuminate\Support\Facades\DB;

class DispatchScheduledImports
{
    /**
     * Move due schedules into the normal import queue and return their count.
     */
    public function handle(): int
    {
        $imports = DB::transaction(function (): array {
            $dueSchedules = ScheduledImport::query()
                ->where('status', 'scheduled')
                ->where('scheduled_for', '<=', now())
                ->orderBy('scheduled_for')
                ->limit(100)
                ->lockForUpdate()
                ->get();

            return $dueSchedules->map(function (ScheduledImport $schedule): InvoiceImport {
                $import = InvoiceImport::create([
                    'team_id' => $schedule->team_id,
                    'uploaded_by' => $schedule->created_by,
                    'status' => InvoiceImportStatus::Pending,
                    'storage_disk' => $schedule->storage_disk,
                    'storage_path' => $schedule->storage_path,
                    'original_filename' => $schedule->original_filename,
                    'file_hash' => $schedule->file_hash,
                    'file_size' => $schedule->file_size,
                    'auto_sync_qbo' => $schedule->auto_sync_qbo,
                ]);

                $schedule->update([
                    'status' => 'dispatched',
                    'dispatched_at' => now(),
                    'invoice_import_id' => $import->id,
                ]);

                return $import;
            })->all();
        });

        foreach ($imports as $import) {
            ProcessInvoiceImport::dispatch($import->id)
                ->onConnection(config('imports.queue_connection'))
                ->onQueue(config('imports.queue'));
        }

        return count($imports);
    }
}
