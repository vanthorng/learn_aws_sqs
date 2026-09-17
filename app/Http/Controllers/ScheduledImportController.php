<?php

namespace App\Http\Controllers;

use App\Enums\TeamPermission;
use App\Http\Requests\StoreScheduledImportRequest;
use App\Models\ScheduledImport;
use App\Models\Team;
use App\Services\InvoiceSpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ScheduledImportController extends Controller
{
    public function index(Request $request, Team $current_team): Response
    {
        $this->ensureTeamMember($request, $current_team);

        return Inertia::render('schedules/Index', [
            'schedules' => ScheduledImport::query()
                ->where('team_id', $current_team->id)
                ->latest('scheduled_for')
                ->limit(50)
                ->get()
                ->map(fn (ScheduledImport $schedule) => $this->summary($schedule)),
            'canManageImports' => $request->user()->hasTeamPermission($current_team, TeamPermission::ImportInvoices),
        ]);
    }

    public function store(StoreScheduledImportRequest $request, Team $current_team): RedirectResponse
    {
        $this->ensureCanManage($request, $current_team);
        $file = $request->file('file');

        try {
            (new InvoiceSpreadsheetReader($file->getRealPath()))->assertValidTemplate();
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $disk = config('imports.disk');
        $fileHash = hash_file('sha256', $file->getRealPath());
        $path = $file->store('scheduled-imports/'.$current_team->id, $disk);

        ScheduledImport::create([
            'team_id' => $current_team->id,
            'created_by' => $request->user()->id,
            'scheduled_for' => $request->date('scheduled_for'),
            'storage_disk' => $disk,
            'storage_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'file_hash' => $fileHash,
            'file_size' => $file->getSize(),
            'auto_sync_qbo' => $request->boolean('auto_sync_qbo'),
        ]);

        return to_route('schedules.index', $current_team)->with('toast', [
            'type' => 'success',
            'message' => 'Invoice import scheduled.',
        ]);
    }

    public function cancel(Request $request, Team $current_team, ScheduledImport $scheduledImport): RedirectResponse
    {
        $this->ensureCanManage($request, $current_team);
        abort_unless($scheduledImport->team_id === $current_team->id, 404);
        abort_unless($scheduledImport->status === 'scheduled', 422, 'Only upcoming imports can be cancelled.');

        $scheduledImport->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return to_route('schedules.index', $current_team)->with('toast', [
            'type' => 'success',
            'message' => 'Scheduled import cancelled.',
        ]);
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

    private function summary(ScheduledImport $schedule): array
    {
        return [
            'id' => $schedule->id,
            'filename' => $schedule->original_filename,
            'status' => $schedule->status,
            'scheduledFor' => $schedule->scheduled_for->toIso8601String(),
            'autoSyncQbo' => $schedule->auto_sync_qbo,
            'dispatchedAt' => $schedule->dispatched_at?->toIso8601String(),
            'invoiceImportId' => $schedule->invoice_import_id,
        ];
    }
}
