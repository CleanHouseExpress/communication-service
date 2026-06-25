<?php

namespace App\Actions\Queues;

use App\Actions\Conversations\RecordConversationEventAction;
use App\Enums\ConversationEventType;
use App\Models\CommunicationFailedJob;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordFailedCommunicationJobAction
{
    public function __construct(
        private readonly RecordConversationEventAction $recordConversationEvent,
    ) {}

    public function handle(
        string $jobName,
        ?string $tenantId,
        ?string $conversationId,
        ?string $messageId,
        array $payload,
        Throwable $exception,
        int $attempts,
    ): ?CommunicationFailedJob {
        $safeMessage = $this->safeMessage($exception->getMessage());

        try {
            $failedJob = CommunicationFailedJob::create([
                'tenant_id' => $tenantId,
                'job_name' => $jobName,
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'payload' => $payload,
                'exception_class' => $exception::class,
                'attempts' => max(1, $attempts),
                'failed_at' => now(),
                'metadata' => [
                    'message' => $safeMessage,
                ],
            ]);

            if ((bool) config('communication.queues.failed_event_enabled', true) && $conversationId !== null) {
                $this->recordConversationEvent->handle(
                    eventType: ConversationEventType::JobFailed,
                    tenantId: $tenantId,
                    conversationId: $conversationId,
                    actorType: 'system',
                    messageId: $messageId,
                    description: 'Queue job failed permanently.',
                    metadata: [
                        'job' => $jobName,
                        'attempts' => max(1, $attempts),
                        'exception_class' => $exception::class,
                        'message' => $safeMessage,
                    ],
                );
            }

            return $failedJob;
        } catch (Throwable $recordingException) {
            Log::error('Failed job metadata recording failed.', [
                'tenant_id' => $tenantId,
                'job' => $jobName,
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'error' => $this->safeMessage($recordingException->getMessage()),
            ]);

            return null;
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
