<?php

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

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

