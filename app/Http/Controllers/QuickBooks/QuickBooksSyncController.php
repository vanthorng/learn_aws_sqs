<?php

namespace App\Http\Controllers\QuickBooks;

use App\Enums\InvoiceImportStatus;
use App\Enums\TeamPermission;
use App\Http\Controllers\Controller;
use App\Jobs\SyncImportToQuickBooks;
use App\Models\InvoiceImport;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class QuickBooksSyncController extends Controller
{
    public function sync(Request $request, Team $current_team, InvoiceImport $invoiceImport): RedirectResponse
    {
        $this->ensureCanManage($request, $current_team);
        abort_unless($invoiceImport->team_id === $current_team->id, 404);

        if (! $current_team->quickbooksConnection) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'Please connect your QuickBooks Online account before syncing.',
            ]);
        }

        abort_unless(
            $invoiceImport->status === InvoiceImportStatus::Completed,
            400,
            'Import must be completed before syncing to QuickBooks.'
        );

        SyncImportToQuickBooks::dispatch($invoiceImport->id)
            ->onConnection(config('imports.queue_connection'))
            ->onQueue(config('imports.queue'));

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'QuickBooks sync initiated. Invoices are being pushed in the background.',
        ]);
    }

    private function ensureCanManage(Request $request, Team $team): void
    {
        abort_unless(
            $request->user()->hasTeamPermission($team, TeamPermission::ImportInvoices),
            403
        );
    }
}
