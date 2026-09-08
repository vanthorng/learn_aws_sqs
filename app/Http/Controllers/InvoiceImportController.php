<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceImportStatus;
use App\Enums\TeamPermission;
use App\Http\Requests\StoreInvoiceImportRequest;
use App\Jobs\ProcessInvoiceImport;
use App\Models\InvoiceImport;
use App\Models\Team;
use App\Services\InvoiceSpreadsheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceImportController extends Controller
{
    public function index(Request $request, Team $current_team): Response
    {
        $this->ensureTeamMember($request, $current_team);

        return Inertia::render('imports/Index', [
            'imports' => InvoiceImport::query()->where('team_id', $current_team->id)->latest()->limit(30)->get()->map(fn (InvoiceImport $import) => $this->summary($import)),
            'canManageImports' => $request->user()->hasTeamPermission($current_team, TeamPermission::ImportInvoices),
            'quickbooksConnection' => $current_team->quickbooksConnection ? [
                'connected' => true,
                'companyName' => $current_team->quickbooksConnection->company_name,
                'realmId' => $current_team->quickbooksConnection->realm_id,
                'environment' => $current_team->quickbooksConnection->environment,
            ] : null,
        ]);
    }

    public function store(StoreInvoiceImportRequest $request, Team $current_team): RedirectResponse
    {
        $this->ensureCanManage($request, $current_team);
        $file = $request->file('file');

        try {
            (new InvoiceSpreadsheetReader($file->getRealPath()))->assertValidTemplate();
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $diskName = config('imports.disk');
        $fileHash = hash_file('sha256', $file->getRealPath());
        $path = $file->store('imports/'.$current_team->id, $diskName);
        $import = DB::transaction(function () use ($request, $current_team, $file, $fileHash, $diskName, $path): InvoiceImport {
            return InvoiceImport::create([
                'team_id' => $current_team->id,
                'uploaded_by' => $request->user()->id,
                'status' => InvoiceImportStatus::Pending,
                'storage_disk' => $diskName,
                'storage_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'file_hash' => $fileHash,
                'file_size' => $file->getSize(),
                'auto_sync_qbo' => $request->boolean('auto_sync_qbo'),
            ]);
        });

        $this->dispatch($import);

        return to_route('imports.index', $current_team)->with('toast', ['type' => 'success', 'message' => 'Invoice import queued.']);
    }

    public function show(Request $request, Team $current_team, InvoiceImport $invoiceImport): JsonResponse
    {
        $this->ensureBelongsToTeam($request, $current_team, $invoiceImport);
        $errors = $invoiceImport->rows()
            ->where('status', 'failed')
            ->latest('source_row_number')
            ->paginate(25, ['*'], 'errors_page');
        $records = $invoiceImport->records()
            ->orderBy('source_row_number')
            ->paginate(25, ['*'], 'records_page');

        return response()->json([
            'import' => $this->summary($invoiceImport),
            'errors' => $errors->through(fn ($row) => [
                'rowNumber' => $row->source_row_number,
                'errors' => $row->errors,
                'payload' => $row->payload,
            ]),
            'records' => $records->through(fn ($record) => [
                'rowNumber' => $record->source_row_number,
                'docNumber' => $record->doc_number,
                'customer' => $record->customer,
                'txnDate' => $record->txn_date->toDateString(),
                'lineItem' => $record->line_item,
                'lineAmount' => $record->line_amount,
                'qboInvoiceId' => $record->qbo_invoice_id,
                'qboSyncedAt' => $record->qbo_synced_at instanceof \Carbon\CarbonInterface
                    ? $record->qbo_synced_at->toIso8601String()
                    : ($record->qbo_synced_at ? (string) $record->qbo_synced_at : null),
                'qboSyncError' => $record->qbo_sync_error,
            ]),
        ]);
    }

    public function retry(Request $request, Team $current_team, InvoiceImport $invoiceImport): RedirectResponse
    {
        $this->ensureCanManage($request, $current_team);
        $this->ensureBelongsToTeam($request, $current_team, $invoiceImport);
        abort_unless(
            $invoiceImport->status === InvoiceImportStatus::Failed ||
            ($invoiceImport->status === InvoiceImportStatus::Completed && $invoiceImport->failed_count > 0),
            422,
            'Only failed imports or completed imports with row errors can be retried.',
        );
        abort_unless(Storage::disk($invoiceImport->storage_disk)->exists($invoiceImport->storage_path), 422, 'The original import file is no longer available.');

        $invoiceImport->update([
            'status' => InvoiceImportStatus::Pending,
            'processed_rows' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'percentage' => 0,
            'started_at' => null,
            'completed_at' => null,
            'error_summary' => null,
        ]);
        $this->dispatch($invoiceImport);

        return to_route('imports.index', $current_team)->with('toast', ['type' => 'success', 'message' => 'Invoice import queued for retry.']);
    }

    private function dispatch(InvoiceImport $import): void
    {
        ProcessInvoiceImport::dispatch($import->id)
            ->onConnection(config('imports.queue_connection'))
            ->onQueue(config('imports.queue'));
    }

    private function ensureTeamMember(Request $request, Team $team): void
    {
        abort_unless($request->user()?->belongsToTeam($team), 403);
    }

    private function ensureCanManage(Request $request, Team $team): void
    {
        $this->ensureTeamMember($request, $team);
        abort_unless($request->user()->hasTeamPermission($team, TeamPermission::ImportInvoices), 403);
    }

    private function ensureBelongsToTeam(Request $request, Team $team, InvoiceImport $import): void
    {
        $this->ensureTeamMember($request, $team);
        abort_unless($import->team_id === $team->id, 404);
    }

    private function summary(InvoiceImport $import): array
    {
        $parseDuration = null;
        if ($import->started_at && $import->completed_at) {
            $diff = max(1, $import->completed_at->diffInSeconds($import->started_at));
            $parseDuration = $diff >= 60 ? sprintf('%dm %02ds', intdiv($diff, 60), $diff % 60) : "{$diff}s";
        }

        $qboSyncDuration = null;
        $syncStart = $import->qbo_sync_started_at ?? $import->completed_at ?? $import->started_at;
        if ($syncStart && $import->qbo_last_synced_at) {
            $diff = max(1, $import->qbo_last_synced_at->diffInSeconds($syncStart));
            $qboSyncDuration = $diff >= 60 ? sprintf('%dm %02ds', intdiv($diff, 60), $diff % 60) : "{$diff}s";
        }

        $totalDuration = null;
        $finalEnd = $import->qbo_last_synced_at ?? $import->completed_at;
        if ($import->started_at && $finalEnd) {
            $diff = max(1, $finalEnd->diffInSeconds($import->started_at));
            $totalDuration = $diff >= 60 ? sprintf('%dm %02ds', intdiv($diff, 60), $diff % 60) : "{$diff}s";
        }

        return [
            'id' => $import->id,
            'filename' => $import->original_filename,
            'status' => $import->status->value,
            'totalRows' => $import->total_rows,
            'processedRows' => $import->processed_rows,
            'successCount' => $import->success_count,
            'failedCount' => $import->failed_count,
            'percentage' => $import->percentage,
            'attemptCount' => $import->attempt_count,
            'errorSummary' => $import->error_summary,
            'createdAt' => $import->created_at->toIso8601String(),
            'completedAt' => $import->completed_at?->toIso8601String(),
            'qboSyncStatus' => $import->qbo_sync_status ?? 'not_synced',
            'qboSyncedCount' => $import->qbo_synced_count ?? 0,
            'qboFailedCount' => $import->qbo_failed_count ?? 0,
            'qboTotalInvoices' => $import->qbo_total_invoices ?? 0,
            'qboCurrentMessage' => $import->qbo_current_message,
            'qboLastSyncedAt' => $import->qbo_last_synced_at instanceof \Carbon\CarbonInterface
                ? $import->qbo_last_synced_at->toIso8601String()
                : ($import->qbo_last_synced_at ? (string) $import->qbo_last_synced_at : null),
            'qboSyncError' => $import->qbo_sync_error,
            'parseDuration' => $parseDuration,
            'qboSyncDuration' => $qboSyncDuration,
            'totalDuration' => $totalDuration,
        ];
    }
}
