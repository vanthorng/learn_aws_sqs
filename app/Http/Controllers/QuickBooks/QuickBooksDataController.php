<?php

namespace App\Http\Controllers\QuickBooks;

use App\Enums\TeamPermission;
use App\Http\Controllers\Controller;
use App\Jobs\ExportQuickbooksInvoices;
use App\Jobs\DeleteQuickbooksInvoices;
use App\Jobs\VoidQuickbooksInvoices;
use App\Models\QuickbooksOperation;
use App\Models\QuickbooksOperationItem;
use App\Models\Team;
use App\Services\QuickBooks\QuickBooksClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QuickBooksDataController extends Controller
{
    public function index(Request $request, Team $current_team): Response
    {
        $this->ensureCanManage($request, $current_team);

        return Inertia::render('quickbooks/Data', [
            'connected' => $current_team->quickbooksConnection !== null,
            'operations' => $current_team->quickbooksOperations()->latest()->limit(20)->get()->map(fn (QuickbooksOperation $operation) => $this->operation($operation)),
        ]);
    }

    public function invoices(Request $request, Team $current_team, QuickBooksClient $client): JsonResponse
    {
        $this->ensureConnected($request, $current_team);
        $validated = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'statuses' => ['required', 'array', 'min:1'],
            'statuses.*' => [Rule::in(['open', 'paid', 'voided'])],
            'customer' => ['nullable', 'string', 'max:255'],
            'doc_number' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $results = $client->browseInvoices($current_team->quickbooksConnection, $validated);

        return response()->json([
            'data' => array_map(fn (array $invoice) => [
                'qboInvoiceId' => (string) ($invoice['Id'] ?? ''),
                'docNumber' => (string) ($invoice['DocNumber'] ?? ''),
                'customer' => (string) ($invoice['CustomerRef']['name'] ?? ''),
                'txnDate' => $invoice['TxnDate'] ?? null,
                'dueDate' => $invoice['DueDate'] ?? null,
                'total' => $invoice['TotalAmt'] ?? 0,
                'balance' => $invoice['Balance'] ?? 0,
                'status' => $client->invoiceStatus($invoice),
                'canVoid' => $client->invoiceStatus($invoice) !== 'voided',
                'canDelete' => true,
            ], $results['data']),
            'meta' => $results['meta'],
        ]);
    }

    public function createExport(Request $request, Team $current_team): RedirectResponse
    {
        $this->ensureConnected($request, $current_team);
        $validated = $request->validate([
            'from_date' => ['required', 'date'], 'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'statuses' => ['required', 'array', 'min:1'], 'statuses.*' => [Rule::in(['open', 'paid', 'voided'])],
            'customer' => ['nullable', 'string', 'max:255'], 'doc_number' => ['nullable', 'string', 'max:255'],
        ]);
        $operation = QuickbooksOperation::create([
            'team_id' => $current_team->id, 'requested_by' => $request->user()->id, 'type' => 'export', 'status' => 'queued',
            'filters' => [
                'from_date' => $validated['from_date'], 'to_date' => $validated['to_date'], 'statuses' => array_values($validated['statuses']),
                'customer' => $validated['customer'] ?? null, 'doc_number' => $validated['doc_number'] ?? null,
            ],
        ]);
        ExportQuickbooksInvoices::dispatch($operation->id)->onConnection(config('imports.queue_connection'))->onQueue(config('imports.queue'));

        return back()->with('toast', ['type' => 'success', 'message' => 'QuickBooks export queued.']);
    }

    public function void(Request $request, Team $current_team): RedirectResponse
    {
        $this->ensureConnected($request, $current_team);
        $validated = $request->validate([
            'confirmation' => ['required', 'in:VOID'],
            'qbo_invoice_ids' => ['required', 'array', 'min:1', 'max:100'],
            'qbo_invoice_ids.*' => ['string', 'distinct'],
        ]);
        $invoiceIds = array_values(array_unique($validated['qbo_invoice_ids']));

        $operation = DB::transaction(function () use ($current_team, $request, $invoiceIds): QuickbooksOperation {
            $operation = QuickbooksOperation::create([
                'team_id' => $current_team->id, 'requested_by' => $request->user()->id, 'type' => 'void', 'status' => 'queued', 'total_count' => count($invoiceIds),
            ]);
            foreach ($invoiceIds as $invoiceId) QuickbooksOperationItem::create(['quickbooks_operation_id' => $operation->id, 'qbo_invoice_id' => $invoiceId]);
            return $operation;
        });
        VoidQuickbooksInvoices::dispatch($operation->id)->onConnection(config('imports.queue_connection'))->onQueue(config('imports.queue'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Invoice void operation queued.']);
    }

    public function destroy(Request $request, Team $current_team): RedirectResponse
    {
        $this->ensureConnected($request, $current_team);
        $validated = $request->validate([
            'confirmation' => ['required', 'in:DELETE'],
            'qbo_invoice_ids' => ['required', 'array', 'min:1', 'max:100'],
            'qbo_invoice_ids.*' => ['string', 'distinct'],
        ]);
        $invoiceIds = array_values(array_unique($validated['qbo_invoice_ids']));

        $operation = DB::transaction(function () use ($current_team, $request, $invoiceIds): QuickbooksOperation {
            $operation = QuickbooksOperation::create([
                'team_id' => $current_team->id, 'requested_by' => $request->user()->id, 'type' => 'delete', 'status' => 'queued', 'total_count' => count($invoiceIds),
            ]);
            foreach ($invoiceIds as $invoiceId) QuickbooksOperationItem::create(['quickbooks_operation_id' => $operation->id, 'qbo_invoice_id' => $invoiceId]);

            return $operation;
        });
        DeleteQuickbooksInvoices::dispatch($operation->id)->onConnection(config('imports.queue_connection'))->onQueue(config('imports.queue'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Invoice deletion operation queued.']);
    }

    public function show(Request $request, Team $current_team, QuickbooksOperation $operation): JsonResponse
    {
        $this->ensureCanManage($request, $current_team);
        abort_unless($operation->team_id === $current_team->id, 404);

        return response()->json(['operation' => $this->operation($operation)]);
    }

    public function download(Request $request, Team $current_team, QuickbooksOperation $operation)
    {
        $this->ensureCanManage($request, $current_team);
        abort_unless($operation->team_id === $current_team->id && $operation->type === 'export' && $operation->status === 'completed', 404);
        abort_unless($operation->expires_at?->isFuture() && Storage::disk($operation->storage_disk)->exists($operation->storage_path), 404, 'This export is no longer available.');

        return Storage::disk($operation->storage_disk)->download($operation->storage_path, $operation->download_filename);
    }

    private function ensureConnected(Request $request, Team $team): void
    {
        $this->ensureCanManage($request, $team);
        abort_unless($team->quickbooksConnection, 422, 'Connect QuickBooks before using this feature.');
    }

    private function ensureCanManage(Request $request, Team $team): void
    {
        abort_unless($request->user()->hasTeamPermission($team, TeamPermission::ImportInvoices), 403);
    }

    private function operation(QuickbooksOperation $operation): array
    {
        return [
            'id' => $operation->id, 'type' => $operation->type, 'status' => $operation->status, 'filters' => $operation->filters,
            'totalCount' => $operation->total_count, 'processedCount' => $operation->processed_count, 'successCount' => $operation->success_count,
            'failedCount' => $operation->failed_count, 'percentage' => $operation->percentage, 'errorSummary' => $operation->error_summary,
            'createdAt' => $operation->created_at->toIso8601String(), 'completedAt' => $operation->completed_at?->toIso8601String(),
            'canDownload' => $operation->type === 'export' && $operation->status === 'completed' && $operation->expires_at?->isFuture(),
        ];
    }
}
