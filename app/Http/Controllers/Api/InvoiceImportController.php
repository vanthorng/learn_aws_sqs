<?php

namespace App\Http\Controllers\Api;

use App\Actions\Imports\CreateInvoiceImport;
use App\Http\Controllers\Controller;
use App\Models\InvoiceImport;
use App\Models\Team;
use App\Models\TeamApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceImportController extends Controller
{
    public function store(Request $request, Team $team, CreateInvoiceImport $createImport): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:20480'],
            'auto_sync_qbo' => ['nullable', 'boolean'],
        ]);
        /** @var TeamApiToken $token */
        $token = $request->attributes->get('teamApiToken');
        $import = $createImport->handle($team, $token->creator, $validated['file'], (bool) ($validated['auto_sync_qbo'] ?? false));

        return response()->json([
            'data' => $this->summary($import),
            'message' => 'Invoice import queued.',
        ], 202);
    }

    public function show(Team $team, InvoiceImport $invoiceImport): JsonResponse
    {
        abort_unless($invoiceImport->team_id === $team->id, 404);

        return response()->json(['data' => $this->summary($invoiceImport)]);
    }

    private function summary(InvoiceImport $import): array
    {
        return [
            'id' => $import->id,
            'filename' => $import->original_filename,
            'status' => $import->status->value,
            'total_rows' => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            'success_count' => $import->success_count,
            'failed_count' => $import->failed_count,
            'percentage' => $import->percentage,
            'error_summary' => $import->error_summary,
            'created_at' => $import->created_at->toIso8601String(),
            'completed_at' => $import->completed_at?->toIso8601String(),
            'quickbooks_sync_status' => $import->qbo_sync_status,
        ];
    }
}
