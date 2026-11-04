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

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('qbo-operation.'.$this->operation->id)];
    }

    public function broadcastAs(): string
    {
        return 'quickbooks.operation.updated';
    }

    /** @return array<string, int|string|null> */
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
