<?php

namespace App\Events;

use App\Models\QuickbooksOperation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuickbooksOperationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public QuickbooksOperation $operation) {}

    public function broadcastOn(): array { return [new PrivateChannel('qbo-operation.'.$this->operation->id)]; }
    public function broadcastAs(): string { return 'quickbooks.operation.updated'; }
    public function broadcastWith(): array
    {
        return [
            'operationId' => $this->operation->id,
            'status' => $this->operation->status,
            'processedCount' => $this->operation->processed_count,
            'totalCount' => $this->operation->total_count,
            'successCount' => $this->operation->success_count,
            'failedCount' => $this->operation->failed_count,
            'percentage' => $this->operation->percentage,
            'errorSummary' => $this->operation->error_summary,
        ];
    }
}
