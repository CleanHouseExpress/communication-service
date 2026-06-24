<?php

namespace App\Actions\Agents;

use App\Actions\Messages\ProcessOutboundMessageAction;
use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\DTO\Agents\AgentRequestData;
use App\DTO\Messages\OutboundMessageData;
use App\Enums\AgentRunStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\CommunicationAgentRun;
use App\Models\CommunicationMessage;
use App\Services\Agents\N8nAgentClient;
use App\Support\Security\AgentPromptGuard;
use App\Support\Tenancy\CurrentTenantConnection;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Log;

class DispatchMessageToAgentAction
{
    public function __construct(
        private readonly N8nAgentClient $agentClient,
        private readonly ProcessOutboundMessageAction $processOutboundMessage,
        private readonly AgentPromptGuard $agentPromptGuard,
        private readonly TenantResolver $tenantResolver,
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
    ) {}

    public function handle(CommunicationMessage $message): CommunicationAgentRun
    {
        $this->tenantResolver->enforceIfEnabled($message->tenant_id);
        $hadTenantContext = $this->currentTenantConnection->connectionName() !== null;
        $this->resolveTenantRuntimeConnection->handle($message->tenant_id);

        try {
            $message->loadMissing(['contact', 'conversation', 'channel']);
            $requestData = $this->requestData($message);

            $agentRun = CommunicationAgentRun::create([
                'tenant_id' => $message->tenant_id,
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                'provider' => $message->provider,
                'agent' => config('communication.agent.provider', 'n8n'),
                'status' => AgentRunStatus::Pending->value,
                'request_payload' => $requestData->toArray(),
            ]);

        if (! (bool) config('communication.agent.enabled', false)) {
            $agentRun->forceFill([
                'status' => AgentRunStatus::Skipped->value,
                'response_payload' => [
                    'status' => 'skipped',
                    'reason' => 'Agent is disabled.',
                ],
                'finished_at' => now(),
            ])->save();

            Log::info('Agent run skipped.', [
                'tenant_id' => $agentRun->tenant_id,
                'provider' => $agentRun->provider,
                'message_id' => $agentRun->message_id,
                'conversation_id' => $agentRun->conversation_id,
                'agent_run_id' => $agentRun->id,
                'status' => AgentRunStatus::Skipped->value,
            ]);

                return $agentRun->refresh();
            }

        $agentRun->forceFill([
            'status' => AgentRunStatus::Running->value,
            'started_at' => now(),
        ])->save();

        $responseData = $this->agentClient->dispatch($requestData);

        $agentRun->forceFill([
            'status' => $responseData->success ? AgentRunStatus::Completed->value : AgentRunStatus::Failed->value,
            'response_payload' => $responseData->rawResponse,
            'response_text' => $responseData->responseText,
            'failed_reason' => $responseData->error,
            'finished_at' => now(),
        ])->save();

        Log::{$responseData->success ? 'info' : 'warning'}('Agent run finished.', [
            'tenant_id' => $agentRun->tenant_id,
            'provider' => $agentRun->provider,
            'message_id' => $agentRun->message_id,
            'conversation_id' => $agentRun->conversation_id,
            'agent_run_id' => $agentRun->id,
            'status' => $agentRun->status,
            'error' => $responseData->error,
        ]);

        if ($responseData->success && $responseData->shouldReply && $responseData->responseText !== null && $responseData->responseText !== '') {
            $this->processOutboundMessage->handle(new OutboundMessageData(
                tenantId: (string) $message->tenant_id,
                channelId: (string) $message->channel_id,
                conversationId: (string) $message->conversation_id,
                contactId: (string) $message->contact_id,
                externalContactId: (string) ($message->contact?->phone ?? $message->contact?->external_id),
                messageType: MessageType::Text,
                text: $responseData->responseText,
                idempotencyKey: "agent-run:{$agentRun->id}",
                payload: [
                    'source' => 'agent',
                    'agent_run_id' => $agentRun->id,
                    'should_handoff' => $responseData->shouldHandoff,
                ],
            ));
        }

            return $agentRun->refresh();
        } finally {
            if (! $hadTenantContext) {
                $this->currentTenantConnection->clear();
            }
        }
    }

    private function requestData(CommunicationMessage $message): AgentRequestData
    {
        $guardedInput = $this->agentPromptGuard->inspect($message->text);

        return new AgentRequestData(
            tenantId: $message->tenant_id,
            conversationId: $message->conversation_id,
            messageId: $message->id,
            contactId: $message->contact_id,
            channelId: $message->channel_id,
            provider: $message->provider,
            text: $guardedInput['text'],
            messageType: $message->message_type,
            contactName: $message->contact?->name,
            contactPhone: $message->contact?->phone,
            history: [],
            metadata: [
                'direction' => $message->direction,
                'occurred_at' => $message->occurred_at?->toIso8601String(),
                ...$guardedInput['metadata'],
            ],
        );
    }
}
