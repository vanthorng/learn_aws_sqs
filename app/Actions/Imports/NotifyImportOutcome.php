<?php

namespace App\Actions\Imports;

use App\Enums\TeamPermission;
use App\Models\InvoiceImport;
use App\Models\User;
use App\Notifications\InvoiceImportNotification;
use Illuminate\Support\Facades\Notification;

class NotifyImportOutcome
{
    public function handle(InvoiceImport $import, string $outcome): void
    {
        $import->loadMissing(['team.members', 'uploader']);
        $recipients = $import->team->members
            ->filter(fn (User $user) => $user->hasTeamPermission($import->team, TeamPermission::ImportInvoices))
            ->push($import->uploader)
            ->filter()
            ->unique('id')
            ->values();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new InvoiceImportNotification($import, $outcome));
        }
    }
}
