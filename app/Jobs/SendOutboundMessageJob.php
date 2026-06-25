<?php

namespace App\Jobs;

use App\Actions\Messages\SendPendingOutboundMessageAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendOutboundMessageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $outboundMessageId,
        public readonly ?string $tenantId,
    ) {}

    public function handle(SendPendingOutboundMessageAction $sendPendingOutboundMessage): void
    {
        $outboundMessage = $sendPendingOutboundMessage->handle($this->outboundMessageId, $this->tenantId);

        Log::info('Outbound send job completed.', [
            'tenant_id' => $this->tenantId,
            'outbound_message_id' => $this->outboundMessageId,
            'message_id' => $outboundMessage?->communication_message_id,
            'conversation_id' => $outboundMessage?->conversation_id,
            'status' => $outboundMessage?->status ?? 'not_found',
        ]);
    }
}
