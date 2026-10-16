<?php

namespace App\Actions\Imports;

use App\Enums\InvoiceImportStatus;
use App\Jobs\ProcessInvoiceImport;
use App\Models\InvoiceImport;
use App\Models\Team;
use App\Models\User;
use App\Services\InvoiceSpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateInvoiceImport
{
    public function handle(Team $team, User $uploader, UploadedFile $file, bool $autoSyncQuickBooks = false): InvoiceImport
    {
        try {
            (new InvoiceSpreadsheetReader($file->getRealPath()))->assertValidTemplate();
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $diskName = config('imports.disk');
        $fileHash = hash_file('sha256', $file->getRealPath());
        $path = $file->store('imports/'.$team->id, $diskName);

        $import = DB::transaction(function () use ($team, $uploader, $file, $autoSyncQuickBooks, $diskName, $fileHash, $path): InvoiceImport {
            return InvoiceImport::create([
                'team_id' => $team->id,
                'uploaded_by' => $uploader->id,
                'status' => InvoiceImportStatus::Pending,
                'storage_disk' => $diskName,
                'storage_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'file_hash' => $fileHash,
                'file_size' => $file->getSize(),
                'auto_sync_qbo' => $autoSyncQuickBooks,
            ]);
        });

        ProcessInvoiceImport::dispatch($import->id)
            ->onConnection(config('imports.queue_connection'))
            ->onQueue(config('imports.queue'));

        return $import;
    }
}
