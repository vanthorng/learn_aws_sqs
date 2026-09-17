<?php

namespace App\Notifications;

use App\Models\InvoiceImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceImportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly InvoiceImport $import,
        private readonly string $outcome,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->outcome,
            'title' => $this->title(),
            'message' => $this->message(),
            'import_id' => $this->import->id,
            'team_slug' => $this->import->team->slug,
            'url' => route('imports.index', $this->import->team),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->message())
            ->action('View import', route('imports.index', $this->import->team));
    }

    private function title(): string
    {
        return match ($this->outcome) {
            'import_completed' => 'Invoice import completed',
            'import_failed' => 'Invoice import failed',
            'quickbooks_sync_completed' => 'QuickBooks sync completed',
            default => 'QuickBooks sync needs attention',
        };
    }

    private function message(): string
    {
        $filename = $this->import->original_filename;

        return match ($this->outcome) {
            'import_completed' => "{$filename} finished: {$this->import->success_count} rows imported and {$this->import->failed_count} rows need attention.",
            'import_failed' => "{$filename} could not be imported. {$this->import->error_summary}",
            'quickbooks_sync_completed' => "{$filename} synced {$this->import->qbo_synced_count} invoices to QuickBooks.",
            default => "{$filename} synced {$this->import->qbo_synced_count} invoices; {$this->import->qbo_failed_count} need attention.",
        };
    }
}
