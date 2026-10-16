<?php

namespace App\Http\Controllers;

use App\Models\TeamInvitation;
use App\Models\InvoiceImport;
use App\Models\ScheduledImport;
use App\Models\Team;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, Team $current_team): Response
    {
        $email = strtolower($request->user()->email);

        $pendingInvitations = TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);

        return Inertia::render('Dashboard', [
            'pendingInvitations' => $pendingInvitations,
            'overview' => [
                'queuedImports' => InvoiceImport::query()
                    ->where('team_id', $current_team->id)
                    ->whereIn('status', ['pending', 'processing'])
                    ->count(),
                'completedImports' => InvoiceImport::query()
                    ->where('team_id', $current_team->id)
                    ->where('status', 'completed')
                    ->count(),
                'upcomingSchedules' => ScheduledImport::query()
                    ->where('team_id', $current_team->id)
                    ->where('status', 'scheduled')
                    ->count(),
            ],
            'recentImports' => InvoiceImport::query()
                ->where('team_id', $current_team->id)
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (InvoiceImport $import) => [
                    'id' => $import->id,
                    'filename' => $import->original_filename,
                    'status' => $import->status->value,
                    'percentage' => $import->percentage,
                    'createdAt' => $import->created_at->toIso8601String(),
                ]),
            'nextSchedule' => ScheduledImport::query()
                ->where('team_id', $current_team->id)
                ->where('status', 'scheduled')
                ->orderBy('scheduled_for')
                ->first()
                ?->only(['id', 'original_filename', 'scheduled_for']),
        ]);
    }
}
