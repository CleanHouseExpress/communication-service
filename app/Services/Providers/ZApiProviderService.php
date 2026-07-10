<?php

namespace App\Services\Providers;

use App\DTO\Messages\InboundMessageData;
use App\DTO\Providers\ZapiSendResult;
use App\Enums\MessageStatus;
use App\Models\CommunicationChannel;
use App\Models\CommunicationTenant;
use App\Services\Security\PayloadSanitizer;
use App\Support\Normalization\ZapiWebhookNormalizer;
use App\Support\Security\ConfiguredUrlGuard;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class ZApiProviderService
{
    public function __construct(
        private readonly ConfiguredUrlGuard $configuredUrlGuard,
        private readonly PayloadSanitizer $payloadSanitizer,
        private readonly ZapiWebhookNormalizer $normalizer,
    ) {}

    public function createInstanceForTenant(?CommunicationTenant $tenant, CommunicationChannel $channel): array
    {
        if ($this->fakeEnabled()) {
            $instanceId = 'fake-zapi-instance-'.$channel->id;
            $instanceToken = 'fake-zapi-token-'.$channel->id;

            $this->logInfo('Z-API instance created.', $channel, [
                'event' => 'instance_created',
                'fake' => true,
                'tenant_id' => $tenant?->orchestra_tenant_id ?? $channel->tenant_id,
            ]);

            return [
                'success' => true,
                'instance_id' => $instanceId,
                'instance_token' => $instanceToken,
                'response' => ['fake' => true],
            ];
        }

        try {
            $partnerToken = $this->secretConfig('communication.providers.zapi.partner_token');
            if ($partnerToken === null) {
                return $this->failure('Z-API partner token is not configured.');
            }

            $apiUrl = rtrim((string) config('communication.providers.zapi.api_url', 'https://api.z-api.io'), '/');
            $urlError = $this->configuredUrlGuard->validate($apiUrl, 'Z-API Partner API');
            if ($urlError !== null) {
                return $this->failure($urlError);
            }

            $payload = array_filter([
                'name' => $channel->name,
                'tenant_id' => $tenant?->orchestra_tenant_id ?? $channel->tenant_id,
                'channel_id' => $channel->id,
                'expected_phone_number' => $channel->expected_phone_number,
            ], static fn ($value): bool => $value !== null && $value !== '');

            $response = Http::withToken($partnerToken)
                ->acceptJson()
                ->timeout((int) config('communication.providers.zapi.timeout', 15))
                ->withoutRedirecting()
                ->baseUrl($apiUrl)
                ->post($this->path('create_instance'), $payload);

            $body = $response->json();
            $responsePayload = is_array($body) ? $body : ['body' => $response->body()];

            if (! $response->successful() || (bool) ($responsePayload['error'] ?? false)) {
                return $this->failure('Z-API instance creation failed.', [
                    'http_status' => $response->status(),
                    'response' => $this->payloadSanitizer->sanitize($responsePayload),
                ]);
            }

            $instanceId = $this->firstScalar($responsePayload, ['instanceId', 'instance_id', 'id']);
            $instanceToken = $this->firstScalar($responsePayload, ['instanceToken', 'instance_token', 'token']);

            if ($instanceId === null || $instanceToken === null) {
                return $this->failure('Z-API instance creation did not return credentials.', [
                    'http_status' => $response->status(),
                    'response' => $this->payloadSanitizer->sanitize($responsePayload),
                ]);
            }

            $this->logInfo('Z-API instance created.', $channel, [
                'event' => 'instance_created',
                'http_status' => $response->status(),
            ]);

            return [
                'success' => true,
                'http_status' => $response->status(),
                'instance_id' => $instanceId,
                'instance_token' => $instanceToken,
                'response' => $this->payloadSanitizer->sanitize($responsePayload),
            ];
        } catch (\Throwable $exception) {
            return $this->failure($this->safeError($exception->getMessage()));
        }
    }

    public function getQrCode(CommunicationChannel $channel): array
    {
        if ($this->fakeEnabled()) {
            $channel->forceFill([
                'status' => 'qr_pending',
                'last_status_check_at' => now(),
            ])->save();

            $this->logInfo('Z-API QR Code generated.', $channel, [
                'event' => 'qr_generated',
                'fake' => true,
            ]);

            return [
                'success' => true,
                'status' => 'qr_pending',
                'qr_code' => 'fake-zapi-qr-code',
                'image' => 'data:image/png;base64,ZmFrZS16YXBpLXFy',
            ];
        }

        $response = $this->request($channel, 'get', $this->path('qr_code'));

        if (! $response['success']) {
            return $response;
        }

        $channel->forceFill([
            'status' => 'qr_pending',
            'last_status_check_at' => now(),
        ])->save();

        $this->logInfo('Z-API QR Code generated.', $channel, [
            'event' => 'qr_generated',
            'response' => $response['response'],
        ]);

        return [
            'success' => true,
            'status' => 'qr_pending',
            'response' => $response['response'],
            'qr_code' => $this->firstScalar($response['response'], ['qrCode', 'qr_code', 'value', 'code']),
            'image' => $this->firstScalar($response['response'], ['image', 'base64', 'qrCodeBase64', 'qr_code_base64']),
        ];
    }

    public function getConnectionStatus(CommunicationChannel $channel): array
    {
        if ($this->fakeEnabled()) {
            $channel->forceFill(['last_status_check_at' => now()])->save();

            return [
                'success' => true,
                'status' => $channel->status,
                'connected' => $channel->status === 'connected',
            ];
        }

        $response = $this->request($channel, 'get', $this->path('status'));

        if (! $response['success']) {
            return $response;
        }

        $connected = $this->isConnectedResponse($response['response']);
        $updates = ['last_status_check_at' => now()];

        if ($connected) {
            $updates['status'] = 'connected';
            $updates['last_connected_at'] = $channel->last_connected_at ?? now();
            $updates['connected_phone_number'] = $this->phoneFromPayload($response['response']) ?? $channel->connected_phone_number;
        }

        $channel->forceFill($updates)->save();

        return [
            'success' => true,
            'status' => $connected ? 'connected' : ($channel->status ?: 'disconnected'),
            'connected' => $connected,
            'response' => $response['response'],
        ];
    }

    public function disconnect(CommunicationChannel $channel): array
    {
        if ($this->fakeEnabled()) {
            $channel->forceFill([
                'status' => 'disconnected',
                'last_disconnected_at' => now(),
                'last_status_check_at' => now(),
            ])->save();

            $this->logInfo('Z-API channel disconnected.', $channel, [
                'event' => 'disconnected',
                'fake' => true,
            ]);

            return ['success' => true, 'status' => 'disconnected'];
        }

        $response = $this->request($channel, 'post', $this->path('disconnect'));

        if (! $response['success']) {
            return $response;
        }

        $channel->forceFill([
            'status' => 'disconnected',
            'last_disconnected_at' => now(),
            'last_status_check_at' => now(),
        ])->save();

        $this->logInfo('Z-API channel disconnected.', $channel, [
            'event' => 'disconnected',
            'response' => $response['response'],
        ]);

        return [
            'success' => true,
            'status' => 'disconnected',
            'response' => $response['response'],
        ];
    }

    public function configureWebhooks(CommunicationChannel $channel): array
    {
        $webhooks = $this->webhookUrls($channel);

        if (app()->environment('production')) {
            foreach ($webhooks as $url) {
                if (! str_starts_with($url, 'https://')) {
                    return $this->failure('Z-API webhooks must use HTTPS in production.');
                }
            }
        }

        if ($this->fakeEnabled()) {
            $this->logInfo('Z-API webhooks configured.', $channel, [
                'event' => 'webhooks_configured',
                'fake' => true,
                'webhooks' => $webhooks,
            ]);

            return ['success' => true, 'webhooks' => $webhooks];
        }

        $results = [
            'messages' => $this->request($channel, 'post', $this->path('webhook_messages'), ['value' => $webhooks['messages']]),
            'message_status' => $this->request($channel, 'post', $this->path('webhook_message_status'), ['value' => $webhooks['message_status']]),
            'connected' => $this->request($channel, 'post', $this->path('webhook_connected'), ['value' => $webhooks['connected']]),
            'disconnected' => $this->request($channel, 'post', $this->path('webhook_disconnected'), ['value' => $webhooks['disconnected']]),
        ];

        $success = collect($results)->every(fn (array $result): bool => $result['success'] === true);

        $this->logInfo($success ? 'Z-API webhooks configured.' : 'Z-API webhook configuration failed.', $channel, [
            'event' => 'webhooks_configured',
            'success' => $success,
            'results' => $results,
        ]);

        return [
            'success' => $success,
            'webhooks' => $webhooks,
            'results' => $results,
            'error' => $success ? null : 'One or more Z-API webhook endpoints could not be configured.',
        ];
    }

    public function sendMessage(CommunicationChannel $channel, array $payload): ZapiSendResult
    {
        if ($this->fakeEnabled()) {
            if ((bool) config('communication.providers.zapi.fake_failure', false)) {
                $error = 'Fake Z-API failure enabled.';

                $this->logWarning('Z-API fake send failed.', $channel, [
                    'status' => 'failed',
                    'error' => $error,
                ]);

                return ZapiSendResult::failure($error, [
                    'fake' => true,
                    'phone' => $payload['phone'] ?? null,
                ]);
            }

            $providerMessageId = 'fake-zapi-'.uniqid('', true);

            $this->logInfo('Z-API fake send succeeded.', $channel, [
                'message_id' => $providerMessageId,
                'status' => 'sent',
            ]);

            return ZapiSendResult::success($providerMessageId, [
                'fake' => true,
                'messageId' => $providerMessageId,
                'phone' => $payload['phone'] ?? null,
                'status' => 'sent',
            ]);
        }

        $response = $this->request($channel, 'post', $this->path('send_text'), $payload);

        if (! $response['success']) {
            return ZapiSendResult::failure((string) $response['error'], $response);
        }

        $providerMessageId = $this->firstScalar($response['response'], [
            'messageId',
            'messageID',
            'provider_message_id',
            'id',
            'zaapId',
        ]);

        if ($providerMessageId === null) {
            return ZapiSendResult::failure('Z-API did not acknowledge the message.', $response);
        }

        $this->logInfo('Z-API send succeeded.', $channel, [
            'message_id' => $providerMessageId,
            'status' => 'sent',
        ]);

        return ZapiSendResult::success($providerMessageId, $response);
    }

    public function parseIncomingMessage(array $payload, ?CommunicationChannel $channel = null): InboundMessageData
    {
        return $this->normalizer->normalize(
            $payload,
            tenantId: $channel?->tenant_id ?? $this->firstScalar($payload, ['tenant_id', 'tenant.id', 'orchestra_tenant_id']),
            channelId: $channel?->id,
        );
    }

    public function parseMessageStatus(array $payload): array
    {
        $status = $this->mapMessageStatus($this->firstScalar($payload, [
            'status',
            'messageStatus',
            'message_status',
            'event',
            'type',
        ]));

        return [
            'tenant_id' => $this->firstScalar($payload, ['tenant_id', 'tenant.id', 'orchestra_tenant_id']),
            'provider_message_id' => $this->firstScalar($payload, ['provider_message_id', 'messageId', 'message_id', 'id', 'zaapId']),
            'external_message_id' => $this->firstScalar($payload, ['external_message_id', 'externalMessageId']),
            'status' => $status,
            'timestamp' => $this->timestamp($payload)->toIso8601String(),
        ];
    }

    public function parseConnectionEvent(array $payload): array
    {
        return [
            'tenant_id' => $this->firstScalar($payload, ['tenant_id', 'tenant.id', 'orchestra_tenant_id']),
            'status' => 'connected',
            'phone' => $this->phoneFromPayload($payload),
            'timestamp' => $this->timestamp($payload),
            'payload' => $this->payloadSanitizer->sanitize($payload),
        ];
    }

    public function parseDisconnectionEvent(array $payload): array
    {
        return [
            'tenant_id' => $this->firstScalar($payload, ['tenant_id', 'tenant.id', 'orchestra_tenant_id']),
            'status' => 'disconnected',
            'timestamp' => $this->timestamp($payload),
            'payload' => $this->payloadSanitizer->sanitize($payload),
        ];
    }

    public function sanitizedPayload(array $payload): array
    {
        return $this->payloadSanitizer->sanitize($payload);
    }

    private function request(CommunicationChannel $channel, string $method, string $path, array $payload = []): array
    {
        try {
            $credentials = $this->credentials($channel);
            $baseUrl = $this->baseUrl($credentials['instance_id'], $credentials['instance_token']);
            $urlError = $this->configuredUrlGuard->validate($baseUrl, 'Z-API');

            if ($urlError !== null) {
                return $this->failure($urlError);
            }

            $http = Http::withHeaders([
                'Client-Token' => $credentials['client_token'],
                'Accept' => 'application/json',
            ])
                ->timeout((int) config('communication.providers.zapi.timeout', 15))
                ->withoutRedirecting()
                ->baseUrl($baseUrl);

            $response = $method === 'get'
                ? $http->get($path, $payload)
                : $http->{$method}($path, $payload);

            $body = $response->json();
            $responsePayload = is_array($body) ? $body : ['body' => $response->body()];

            if (! $response->successful() || (bool) ($responsePayload['error'] ?? false)) {
                return $this->failure('Z-API request failed.', [
                    'http_status' => $response->status(),
                    'response' => $this->payloadSanitizer->sanitize($responsePayload),
                ]);
            }

            return [
                'success' => true,
                'http_status' => $response->status(),
                'response' => $this->payloadSanitizer->sanitize($responsePayload),
            ];
        } catch (\Throwable $exception) {
            return $this->failure($this->safeError($exception->getMessage()));
        }
    }

    private function credentials(CommunicationChannel $channel): array
    {
        $settings = $channel->settings ?? [];

        $instanceId = $this->setting($settings, ['zapi.instance_id', 'zapi.instanceId', 'instance_id', 'instanceId'])
            ?? config('communication.providers.zapi.instance_id')
            ?? $channel->external_id;
        $instanceToken = $this->setting($settings, ['zapi.instance_token', 'zapi.instanceToken', 'zapi.token', 'instance_token', 'instanceToken', 'token'])
            ?? config('communication.providers.zapi.token');
        $clientToken = $this->setting($settings, ['zapi.client_token', 'zapi.clientToken', 'client_token', 'clientToken', 'Client-Token'])
            ?? config('communication.providers.zapi.client_token');

        $credentials = [
            'instance_id' => $this->decryptSecret($instanceId),
            'instance_token' => $this->decryptSecret($instanceToken),
            'client_token' => $this->decryptSecret($clientToken),
        ];

        foreach ($credentials as $key => $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException("Z-API channel credential [{$key}] is not configured.");
            }
        }

        return array_map(fn (string $value): string => trim($value), $credentials);
    }

    private function decryptSecret(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        $secret = trim((string) $value);

        try {
            return Crypt::decryptString($secret);
        } catch (DecryptException) {
            return $secret;
        }
    }

    private function baseUrl(string $instanceId, string $instanceToken): string
    {
        $configuredBaseUrl = config('communication.providers.zapi.base_url');

        if (is_string($configuredBaseUrl) && $configuredBaseUrl !== '') {
            return rtrim(strtr($configuredBaseUrl, [
                '{instanceId}' => rawurlencode($instanceId),
                '{instanceToken}' => rawurlencode($instanceToken),
                '{token}' => rawurlencode($instanceToken),
            ]), '/');
        }

        $apiUrl = rtrim((string) config('communication.providers.zapi.api_url', 'https://api.z-api.io'), '/');

        return "{$apiUrl}/instances/{$instanceId}/token/{$instanceToken}";
    }

    private function webhookUrls(CommunicationChannel $channel): array
    {
        $baseUrl = $this->webhookBaseUrl();

        return [
            'messages' => "{$baseUrl}/api/webhooks/z-api/{$channel->id}/messages",
            'message_status' => "{$baseUrl}/api/webhooks/z-api/{$channel->id}/message-status",
            'connected' => "{$baseUrl}/api/webhooks/z-api/{$channel->id}/connected",
            'disconnected' => "{$baseUrl}/api/webhooks/z-api/{$channel->id}/disconnected",
        ];
    }

    private function webhookBaseUrl(): string
    {
        $configured = config('communication.providers.zapi.webhook_base_url');

        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/');
        }

        return rtrim(URL::to('/'), '/');
    }

    private function setting(array $settings, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = Arr::get($settings, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function path(string $key): string
    {
        return (string) config("communication.providers.zapi.paths.{$key}");
    }

    private function secretConfig(string $key): ?string
    {
        $value = config($key);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function firstScalar(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($payload, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function mapMessageStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'sent', 'sended', 'send', 'enviado' => MessageStatus::Sent->value,
            'delivered', 'delivery', 'received', 'entregue' => MessageStatus::Delivered->value,
            'read', 'viewed', 'visualized', 'lido' => MessageStatus::Read->value,
            'failed', 'failure', 'error', 'undelivered', 'erro' => MessageStatus::Failed->value,
            default => MessageStatus::Sent->value,
        };
    }

    private function timestamp(array $payload): CarbonImmutable
    {
        $value = $this->firstScalar($payload, ['timestamp', 'createdAt', 'created_at', 'date']);

        if ($value !== null) {
            try {
                return is_numeric($value)
                    ? CarbonImmutable::createFromTimestamp((int) $value)
                    : CarbonImmutable::parse($value);
            } catch (\Throwable) {
                //
            }
        }

        return CarbonImmutable::now();
    }

    private function isConnectedResponse(array $payload): bool
    {
        $value = $this->firstScalar($payload, ['connected', 'isConnected', 'status', 'value']);

        return in_array(strtolower((string) $value), ['1', 'true', 'connected', 'open', 'online'], true);
    }

    private function phoneFromPayload(array $payload): ?string
    {
        return $this->firstScalar($payload, [
            'phone',
            'phone_number',
            'connected_phone',
            'connected_phone_number',
            'number',
            'session.phone',
            'instance.phone',
        ]);
    }

    private function fakeEnabled(): bool
    {
        return (bool) config('communication.providers.zapi.fake', true);
    }

    private function failure(string $error, array $context = []): array
    {
        return [
            'success' => false,
            'error' => $this->safeError($error),
            ...$context,
        ];
    }

    private function safeError(string $error): string
    {
        $error = preg_replace('/\/token\/[^\/\s&]+/i', '/token/[redacted]', $error) ?? $error;

        return substr(preg_replace('/(token|authorization|client-token|secret)=?[^\\s&]*/i', '$1=[redacted]', $error) ?? $error, 0, 300);
    }

    private function logInfo(string $message, CommunicationChannel $channel, array $context = []): void
    {
        Log::info($message, $this->logContext($channel, $context));
    }

    private function logWarning(string $message, CommunicationChannel $channel, array $context = []): void
    {
        Log::warning($message, $this->logContext($channel, $context));
    }

    private function logContext(CommunicationChannel $channel, array $context): array
    {
        return $this->payloadSanitizer->sanitize([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'provider' => 'zapi',
            ...$context,
        ]);
    }
}
