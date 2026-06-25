<?php

namespace App\Jobs;

use App\Actions\Agents\DispatchMessageToAgentAction;
use App\Actions\Queues\RecordFailedCommunicationJobAction;
use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Enums\AgentRunStatus;
use App\Models\CommunicationMessage;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DispatchAgentForMessageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $messageId,
        public readonly ?string $tenantId,
    ) {}

    public function handle(
        DispatchMessageToAgentAction $dispatchMessageToAgent,
        ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        CurrentTenantConnection $currentTenantConnection,
    ): void {
        $hadTenantContext = $currentTenantConnection->connectionName() !== null;
        $resolveTenantRuntimeConnection->handle($this->tenantId);

        try {
            $query = CommunicationMessage::query();

            if ($this->tenantId !== null && $this->tenantId !== '') {
                $query->where('tenant_id', $this->tenantId);
            } else {
                $query->where(function ($query): void {
                    $query->whereNull('tenant_id')
                        ->orWhere('tenant_id', '');
                });
            }

            $message = $query->where('id', $this->messageId)->first();

            if ($message === null) {
                Log::warning('Agent dispatch job skipped because message was not found.', [
                    'tenant_id' => $this->tenantId,
                    'message_id' => $this->messageId,
                    'status' => 'not_found',
                ]);

                return;
            }

            $agentRun = $dispatchMessageToAgent->handle($message);

            if ($agentRun->status === AgentRunStatus::Failed->value) {
                throw new RuntimeException($agentRun->failed_reason ?: 'Agent dispatch failed.');
            }

            Log::info('Agent dispatch job completed.', [
                'tenant_id' => $this->tenantId,
                'message_id' => $this->messageId,
                'conversation_id' => $message->conversation_id,
                'status' => 'completed',
            ]);
        } catch (Throwable $exception) {
            Log::error('Agent dispatch job failed.', [
                'tenant_id' => $this->tenantId,
                'message_id' => $this->messageId,
                'status' => 'failed',
                'error' => $this->safeMessage($exception->getMessage()),
            ]);

            throw $exception;
        } finally {
            if (! $hadTenantContext) {
                $currentTenantConnection->clear();
            }
        }
    }

    public function tries(): int
    {
        return max(1, (int) config('communication.queues.max_tries', 5));
    }

    public function backoff(): array
    {
        return $this->configuredBackoff();
    }

    public function failed(?Throwable $exception): void
    {
        $exception ??= new RuntimeException('Agent dispatch job failed.');
        $currentTenantConnection = app(CurrentTenantConnection::class);
        $hadTenantContext = $currentTenantConnection->connectionName() !== null;

        try {
            app(ResolveTenantRuntimeConnectionAction::class)->handle($this->tenantId);
            $message = $this->messageQuery()->where('id', $this->messageId)->first();

            app(RecordFailedCommunicationJobAction::class)->handle(
                jobName: class_basename($this),
                tenantId: $this->tenantId,
                conversationId: $message?->conversation_id,
                messageId: $message?->id ?? $this->messageId,
                payload: [
                    'message_id' => $this->messageId,
                    'tenant_id' => $this->tenantId,
                ],
                exception: $exception,
                attempts: $this->attempts(),
            );
        } catch (Throwable $recordingException) {
            Log::error('Agent failed-job callback could not be recorded.', [
                'tenant_id' => $this->tenantId,
                'message_id' => $this->messageId,
                'error' => $this->safeMessage($recordingException->getMessage()),
            ]);
        } finally {
            if (! $hadTenantContext) {
                $currentTenantConnection->clear();
            }
        }
    }

    private function messageQuery()
    {
        $query = CommunicationMessage::query();

        if ($this->tenantId !== null && $this->tenantId !== '') {
            return $query->where('tenant_id', $this->tenantId);
        }

        return $query->where(function ($query): void {
            $query->whereNull('tenant_id')
                ->orWhere('tenant_id', '');
        });
    }

    private function configuredBackoff(): array
    {
        $configured = config('communication.queues.backoff', '10,30,60,120,300');
        $values = is_array($configured) ? $configured : explode(',', (string) $configured);
        $backoff = array_values(array_filter(
            array_map(static fn (mixed $value): int => max(0, (int) trim((string) $value)), $values),
            static fn (int $value): bool => $value > 0,
        ));

        return $backoff !== [] ? $backoff : [10, 30, 60, 120, 300];
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
