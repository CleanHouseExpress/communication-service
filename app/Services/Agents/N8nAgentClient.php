<?php

namespace App\Services\Agents;

use App\DTO\Agents\AgentRequestData;
use App\DTO\Agents\AgentResponseData;
use App\Support\Security\ConfiguredUrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class N8nAgentClient
{
    public function __construct(
        private readonly ConfiguredUrlGuard $configuredUrlGuard,
    ) {}

    public function dispatch(AgentRequestData $requestData): AgentResponseData
    {
        if (! (bool) config('communication.agent.enabled', false)) {
            Log::info('n8n agent skipped because agent is disabled.', [
                'tenant_id' => $requestData->tenantId,
                'provider' => $requestData->provider,
                'message_id' => $requestData->messageId,
                'conversation_id' => $requestData->conversationId,
                'status' => 'skipped',
            ]);

            return AgentResponseData::skipped('Agent is disabled.');
        }

        if ((bool) config('communication.agent.fake', true)) {
            if ((bool) config('communication.agent.fake_failure', false)) {
                Log::warning('n8n fake agent failed.', [
                    'tenant_id' => $requestData->tenantId,
                    'provider' => $requestData->provider,
                    'message_id' => $requestData->messageId,
                    'conversation_id' => $requestData->conversationId,
                    'status' => 'failed',
                    'error' => 'Fake n8n agent failure enabled.',
                ]);

                return AgentResponseData::failure('Fake n8n agent failure enabled.', [
                    'fake' => true,
                ]);
            }

            Log::info('n8n fake agent completed.', [
                'tenant_id' => $requestData->tenantId,
                'provider' => $requestData->provider,
                'message_id' => $requestData->messageId,
                'conversation_id' => $requestData->conversationId,
                'status' => 'completed',
            ]);

            return new AgentResponseData(
                success: true,
                responseText: 'Resposta automatica do agente.',
                shouldReply: true,
                shouldHandoff: false,
                rawResponse: [
                    'fake' => true,
                    'should_reply' => true,
                    'response_text' => 'Resposta automatica do agente.',
                ],
            );
        }

        $webhookUrl = config('communication.agent.n8n_webhook_url');

        if (! is_string($webhookUrl) || $webhookUrl === '') {
            Log::warning('n8n agent failed due missing webhook URL.', [
                'tenant_id' => $requestData->tenantId,
                'provider' => $requestData->provider,
                'message_id' => $requestData->messageId,
                'conversation_id' => $requestData->conversationId,
                'status' => 'failed',
                'error' => 'n8n webhook URL is not configured.',
            ]);

            return AgentResponseData::failure('n8n webhook URL is not configured.');
        }

        $urlError = $this->configuredUrlGuard->validate($webhookUrl, 'n8n webhook');

        if ($urlError !== null) {
            Log::warning('n8n agent failed due unsafe webhook URL.', [
                'tenant_id' => $requestData->tenantId,
                'provider' => $requestData->provider,
                'message_id' => $requestData->messageId,
                'conversation_id' => $requestData->conversationId,
                'status' => 'failed',
                'error' => $urlError,
            ]);

            return AgentResponseData::failure($urlError);
        }

        try {
            $pendingRequest = Http::acceptJson()
                ->timeout((int) config('communication.agent.n8n_timeout', 15))
                ->withoutRedirecting();

            $token = config('communication.agent.n8n_token');

            if (is_string($token) && $token !== '') {
                $pendingRequest = $pendingRequest->withToken($token);
            }

            $response = $pendingRequest->post($webhookUrl, $requestData->toArray());
            $body = $response->json();
            $payload = is_array($body) ? $body : ['body' => $response->body()];

            if (! $response->successful()) {
                Log::warning('n8n agent request failed.', [
                    'tenant_id' => $requestData->tenantId,
                    'provider' => $requestData->provider,
                    'message_id' => $requestData->messageId,
                    'conversation_id' => $requestData->conversationId,
                    'status' => 'failed',
                    'error' => 'n8n agent request failed.',
                ]);

                return AgentResponseData::failure('n8n agent request failed.', [
                    'status' => $response->status(),
                    'response' => $payload,
                ]);
            }

            Log::info('n8n agent request completed.', [
                'tenant_id' => $requestData->tenantId,
                'provider' => $requestData->provider,
                'message_id' => $requestData->messageId,
                'conversation_id' => $requestData->conversationId,
                'status' => 'completed',
            ]);

            return AgentResponseData::fromArray($payload);
        } catch (\Throwable $exception) {
            $error = $this->safeError($exception->getMessage());

            Log::warning('n8n agent request exception.', [
                'tenant_id' => $requestData->tenantId,
                'provider' => $requestData->provider,
                'message_id' => $requestData->messageId,
                'conversation_id' => $requestData->conversationId,
                'status' => 'failed',
                'error' => $error,
            ]);

            return AgentResponseData::failure($error);
        }
    }

    private function safeError(string $error): string
    {
        return substr(preg_replace('/(token|authorization|client-token)=?[^\\s&]*/i', '$1=[redacted]', $error) ?? $error, 0, 300);
    }
}
