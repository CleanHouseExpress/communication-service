<?php

namespace App\Jobs;

use App\Actions\Agents\DispatchMessageToAgentAction;
use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Models\CommunicationMessage;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
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

            $dispatchMessageToAgent->handle($message);

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
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            if (! $hadTenantContext) {
                $currentTenantConnection->clear();
            }
        }
    }
}
