<?php

namespace App\Jobs;

use App\Actions\Messages\SendPendingOutboundMessageAction;
use App\Actions\Queues\RecordFailedCommunicationJobAction;
use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Enums\MessageStatus;
use App\Models\CommunicationOutboundMessage;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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

        if ($outboundMessage?->status === MessageStatus::Failed->value) {
            throw new RuntimeException($outboundMessage->failed_reason ?: 'Outbound message send failed.');
        }

        Log::info('Outbound send job completed.', [
            'tenant_id' => $this->tenantId,
            'outbound_message_id' => $this->outboundMessageId,
            'message_id' => $outboundMessage?->communication_message_id,
            'conversation_id' => $outboundMessage?->conversation_id,
            'status' => $outboundMessage?->status ?? 'not_found',
        ]);
    }

    public function tries(): int
    {
        return max(1, (int) config('communication.queues.max_tries', 5));
    }

    public function backoff(): array
    {
        $configured = config('communication.queues.backoff', '10,30,60,120,300');
        $values = is_array($configured) ? $configured : explode(',', (string) $configured);
        $backoff = array_values(array_filter(
            array_map(static fn (mixed $value): int => max(0, (int) trim((string) $value)), $values),
            static fn (int $value): bool => $value > 0,
        ));

        return $backoff !== [] ? $backoff : [10, 30, 60, 120, 300];
    }

    public function failed(?Throwable $exception): void
    {
        $exception ??= new RuntimeException('Outbound send job failed.');
        $currentTenantConnection = app(CurrentTenantConnection::class);
        $hadTenantContext = $currentTenantConnection->connectionName() !== null;

        try {
            app(ResolveTenantRuntimeConnectionAction::class)->handle($this->tenantId);
            $query = CommunicationOutboundMessage::query();

            if ($this->tenantId !== null && $this->tenantId !== '') {
                $query->where('tenant_id', $this->tenantId);
            } else {
                $query->where(function ($query): void {
                    $query->whereNull('tenant_id')
                        ->orWhere('tenant_id', '');
                });
            }

            $outboundMessage = $query->where('id', $this->outboundMessageId)->first();

            app(RecordFailedCommunicationJobAction::class)->handle(
                jobName: class_basename($this),
                tenantId: $this->tenantId,
                conversationId: $outboundMessage?->conversation_id,
                messageId: $outboundMessage?->communication_message_id,
                payload: [
                    'outbound_message_id' => $this->outboundMessageId,
                    'tenant_id' => $this->tenantId,
                ],
                exception: $exception,
                attempts: $this->attempts(),
            );
        } catch (Throwable $recordingException) {
            Log::error('Outbound failed-job callback could not be recorded.', [
                'tenant_id' => $this->tenantId,
                'outbound_message_id' => $this->outboundMessageId,
                'error' => $this->safeMessage($recordingException->getMessage()),
            ]);
        } finally {
            if (! $hadTenantContext) {
                $currentTenantConnection->clear();
            }
        }
    }

    private function safeMessage(string $message): string
    {
        $redacted = preg_replace(
            '/(token|authorization|client-token|password)([=: ]+)[^\s&]+/i',
            '$1$2[redacted]',
            $message,
        ) ?? $message;

        return mb_substr(trim(preg_replace('/\s+/', ' ', $redacted) ?? $redacted), 0, 300);
    }
}
