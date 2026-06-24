<?php

namespace App\Services\Providers;

use App\DTO\Providers\ZapiSendResult;
use Illuminate\Support\Facades\Http;

class ZapiClient
{
    public function sendText(string $phone, string $message): ZapiSendResult
    {
        if ((bool) config('communication.providers.zapi.fake', true)) {
            if ((bool) config('communication.providers.zapi.fake_failure', false)) {
                return ZapiSendResult::failure('Fake Z-API failure enabled.', [
                    'fake' => true,
                    'phone' => $phone,
                ]);
            }

            $providerMessageId = 'fake-zapi-'.uniqid('', true);

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
            return ZapiSendResult::failure('Z-API is not configured.');
        }

        try {
            $response = Http::withHeaders([
                'Client-Token' => $clientToken,
                'Accept' => 'application/json',
            ])->baseUrl($baseUrl)->post('/send-text', [
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
                return ZapiSendResult::failure('Z-API did not acknowledge the message.', [
                    'status' => $response->status(),
                    'response' => $payload,
                ]);
            }

            return ZapiSendResult::success((string) $providerMessageId, [
                'status' => $response->status(),
                'response' => $payload,
            ]);
        } catch (\Throwable $exception) {
            return ZapiSendResult::failure($exception->getMessage());
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
}
