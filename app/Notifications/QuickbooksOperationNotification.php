<?php

namespace App\Notifications;

use App\Models\QuickbooksOperation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuickbooksOperationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly QuickbooksOperation $operation) {}

    public function via(object $notifiable): array { return ['database', 'mail']; }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title(), 'message' => $this->message(), 'url' => route('quickbooks.data.index', $this->operation->team)];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title())->line($this->message())->action('View QuickBooks data', route('quickbooks.data.index', $this->operation->team));
    }

    private function title(): string
    {
        if ($this->operation->type === 'export') return $this->operation->status === 'completed' ? 'QuickBooks export ready' : 'QuickBooks export failed';
        if ($this->operation->type === 'delete') {
            return $this->operation->failed_count > 0 ? 'QuickBooks deletion needs attention' : 'QuickBooks invoices deleted';
        }

        return $this->operation->failed_count > 0 ? 'QuickBooks void needs attention' : 'QuickBooks invoices voided';
    }

    private function message(): string
    {
        if ($this->operation->type === 'export') {
            return $this->operation->status === 'completed' ? 'Your invoice export is ready to download.' : ($this->operation->error_summary ?? 'Your invoice export failed.');
        }

        $action = $this->operation->type === 'delete' ? 'deleted' : 'voided';
        return "{$this->operation->success_count} invoices {$action}; {$this->operation->failed_count} need attention.";
    }
}
