<?php

use App\Actions\Imports\DispatchScheduledImports;
use App\Models\TeamInvitation;
use App\Models\QuickbooksOperation;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

Schedule::call(function () {
    QuickbooksOperation::query()
        ->where('type', 'export')
        ->whereNotNull('expires_at')
        ->where('expires_at', '<=', now())
        ->whereNotNull('storage_path')
        ->each(function (QuickbooksOperation $operation): void {
            Storage::disk($operation->storage_disk)->delete($operation->storage_path);
            $operation->update(['storage_path' => null, 'storage_disk' => null]);
        });
})->daily()->description('Delete expired QuickBooks export files');

Schedule::command('imports:dispatch-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->description('Dispatch invoice imports that have reached their scheduled time');

Artisan::command('imports:dispatch-scheduled', function (DispatchScheduledImports $dispatchScheduledImports) {
    $count = $dispatchScheduledImports->handle();

    $this->info($count === 1 ? 'Dispatched 1 scheduled import.' : "Dispatched {$count} scheduled imports.");
})->purpose('Dispatch due scheduled invoice imports');

Artisan::command('import:run {id? : The ID of the import to run} {--queue : Dispatch to queue instead of running synchronously}', function ($id = null) {
    $import = $id 
        ? \App\Models\InvoiceImport::find($id) 
        : \App\Models\InvoiceImport::where('status', \App\Enums\InvoiceImportStatus::Pending)->latest()->first();

    if (!$import) {
        $this->error('No pending import found.');
        return 1;
    }

    $this->info("Found Import #{$import->id}: {$import->original_filename} (Status: {$import->status->value})");

    if ($this->option('queue')) {
        \App\Jobs\ProcessInvoiceImport::dispatch($import->id)
            ->onConnection(config('imports.queue_connection'))
            ->onQueue(config('imports.queue'));
        $this->info("Dispatched to queue [".config('imports.queue')."] on connection [".config('imports.queue_connection')."].");
        return 0;
    }

    $this->info("Executing ProcessInvoiceImport synchronously...");
    try {
        (new \App\Jobs\ProcessInvoiceImport($import->id))->handle();
        $import->refresh();
        $this->info("Import finished! Status: {$import->status->value}. Processed: {$import->processed_rows}/{$import->total_rows}, Success: {$import->success_count}, Failed: {$import->failed_count}");
        
        if ($import->auto_sync_qbo) {
            $this->info("Auto-syncing to QuickBooks...");
            (new \App\Jobs\SyncImportToQuickBooks($import->id))->handle(app(\App\Services\QuickBooksClient::class));
            $import->refresh();
            $this->info("QuickBooks sync finished! Status: {$import->qbo_sync_status}. Synced: {$import->qbo_synced_count}/{$import->qbo_total_invoices}, Failed: {$import->qbo_failed_count}");
        }
    } catch (\Throwable $e) {
        $this->error("Error: " . $e->getMessage());
        $this->error($e->getTraceAsString());
        return 1;
    }

    return 0;
})->purpose('Run or dispatch an invoice import directly');
