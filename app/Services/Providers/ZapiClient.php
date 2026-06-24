<?php

namespace App\Services\Providers;

use App\DTO\Providers\ZapiSendResult;
use App\Support\Security\ConfiguredUrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZapiClient
{
    public function __construct(
        private readonly ConfiguredUrlGuard $configuredUrlGuard,
    ) {}

    public function sendText(string $phone, string $message): ZapiSendResult
    {
        if ((bool) config('communication.providers.zapi.fake', true)) {
            if ((bool) config('communication.providers.zapi.fake_failure', false)) {
                Log::warning('Z-API fake send failed.', [
                    'provider' => 'zapi',
                    'status' => 'failed',
                    'error' => 'Fake Z-API failure enabled.',
                ]);

                return ZapiSendResult::failure('Fake Z-API failure enabled.', [
                    'fake' => true,
                    'phone' => $phone,
                ]);
            }

            $providerMessageId = 'fake-zapi-'.uniqid('', true);

            Log::info('Z-API fake send succeeded.', [
                'provider' => 'zapi',
                'message_id' => $providerMessageId,
                'status' => 'sent',
            ]);

            return ZapiSendResult::success($providerMessageId, [
                'fake' => true,
                'messageId' => $providerMessageId,
                'phone' => $phone,
                'status' => 'sent',
            ]);
        }

        $baseUrl = $this->baseUrl();
        $clientToken = config('communication.providers.zapi.client_token');

        if ($baseUrl === null || $clientToken === null || $clientToken === '') {
            Log::warning('Z-API send skipped due missing configuration.', [
                'provider' => 'zapi',
                'status' => 'failed',
                'error' => 'Z-API is not configured.',
            ]);

            return ZapiSendResult::failure('Z-API is not configured.');
        }

        $urlError = $this->configuredUrlGuard->validate($baseUrl, 'Z-API');

        if ($urlError !== null) {
            Log::warning('Z-API send skipped due unsafe URL.', [
                'provider' => 'zapi',
                'status' => 'failed',
                'error' => $urlError,
            ]);

            return ZapiSendResult::failure($urlError);
        }

        try {
            $response = Http::withHeaders([
                'Client-Token' => $clientToken,
                'Accept' => 'application/json',
            ])
                ->timeout((int) config('communication.providers.zapi.timeout', 15))
                ->withoutRedirecting()
                ->baseUrl($baseUrl)
                ->post('/send-text', [
                'phone' => $phone,
                'message' => $message,
            ]);

            $body = $response->json();
            $payload = is_array($body) ? $body : ['body' => $response->body()];
            $providerMessageId = $payload['messageId']
                ?? $payload['messageID']
                ?? $payload['id']
                ?? $payload['zaapId']
                ?? null;

            if (! $response->successful() || ($payload['error'] ?? false) || ! is_scalar($providerMessageId)) {
                Log::warning('Z-API send failed.', [
                    'provider' => 'zapi',
                    'status' => 'failed',
                    'error' => 'Z-API did not acknowledge the message.',
                ]);

                return ZapiSendResult::failure('Z-API did not acknowledge the message.', [
                    'status' => $response->status(),
                    'response' => $payload,
                ]);
            }

            Log::info('Z-API send succeeded.', [
                'provider' => 'zapi',
                'message_id' => (string) $providerMessageId,
                'status' => 'sent',
            ]);

            return ZapiSendResult::success((string) $providerMessageId, [
                'status' => $response->status(),
                'response' => $payload,
            ]);
        } catch (\Throwable $exception) {
            $error = $this->safeError($exception->getMessage());

            Log::warning('Z-API send exception.', [
                'provider' => 'zapi',
                'status' => 'failed',
                'error' => $error,
            ]);

            return ZapiSendResult::failure($error);
        }
    }

    private function baseUrl(): ?string
    {
        $configuredBaseUrl = config('communication.providers.zapi.base_url');

        if (is_string($configuredBaseUrl) && $configuredBaseUrl !== '') {
            return rtrim($configuredBaseUrl, '/');
        }

        $instanceId = config('communication.providers.zapi.instance_id');
        $token = config('communication.providers.zapi.token');

        if (! is_string($instanceId) || $instanceId === '' || ! is_string($token) || $token === '') {
            return null;
        }

        return "https://api.z-api.io/instances/{$instanceId}/token/{$token}";
    }

    private function safeError(string $error): string
    {
        return substr(preg_replace('/(token|authorization|client-token)=?[^\\s&]*/i', '$1=[redacted]', $error) ?? $error, 0, 300);
    }
}
