<?php

namespace App\Services\Messaging;

use Clin\MessagingSdk\DTO\EvolutionResponse;
use Clin\MessagingSdk\Exceptions\RequestException;
use Clin\MessagingSdk\MessagingClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppInstanceManager
{
    private const MISSING_INSTANCE_MESSAGES = [
        'not found',
        'missing',
        'does not exist',
        'inexist',
        'nao existe',
        'não existe',
    ];

    private const ALREADY_EXISTS_MESSAGES = [
        'already exists',
        'already created',
        'already exist',
        'already in use',
        'name already in use',
        'in use',
        'ja existe',
        'já existe',
        'exist',
    ];

    public function __construct(private readonly MessagingClient $messaging) {}

    public function activate(string $instanceName): array
    {
        $this->ensureInstanceExists($instanceName);
        $this->configureWebhook($instanceName);

        return $this->connect($instanceName);
    }

    public function refreshQrCode(string $instanceName): array
    {
        $this->ensureInstanceExists($instanceName);
        $this->configureWebhook($instanceName);

        return $this->connect($instanceName);
    }

    private function ensureInstanceExists(string $instanceName): void
    {
        try {
            $response = $this->messaging->instances()->fetch($instanceName);

            if (! $this->responseIndicatesMissingInstance($response)) {
                return;
            }
        } catch (RequestException $exception) {
            if (! $this->exceptionIndicatesMissingInstance($exception)) {
                throw $exception;
            }
        }

        try {
            $response = $this->messaging->instances()->create($instanceName);

            if (! $this->responseOk($response) && ! $this->responseIndicatesAlreadyExists($response)) {
                throw new RequestException($this->extractMessage($response) ?: 'Evolution instance could not be created.');
            }
        } catch (RequestException $exception) {
            if (! $this->exceptionIndicatesAlreadyExists($exception)) {
                throw $exception;
            }
        }
    }

    private function connect(string $instanceName): array
    {
        try {
            return $this->response($instanceName, $this->messaging->instances()->connect($instanceName));
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'provider' => $this->provider(),
                'instance_name' => $instanceName,
                'status' => 'error',
                'state' => 'error',
                'qr_code' => null,
                'message' => $this->sanitizeMessage($exception->getMessage()),
                'last_updated_at' => now()->toJSON(),
            ];
        }
    }

    private function configureWebhook(string $instanceName): void
    {
        $url = config('messaging.providers.evolution.webhook_url');

        if (! is_string($url) || trim($url) === '') {
            return;
        }

        $events = config('messaging.providers.evolution.webhook_events', []);

        $webhookByEvents = (bool) config('messaging.providers.evolution.webhook_by_events', false);
        $webhookBase64 = (bool) config('messaging.providers.evolution.webhook_base64', false);

        try {
            $response = $this->messaging->webhooks()->set($instanceName, [
                'webhook' => [
                    'enabled' => true,
                    'url' => $url,
                    'webhook_by_events' => $webhookByEvents,
                    'webhook_base64' => $webhookBase64,
                    'events' => is_array($events) ? array_values($events) : [],
                ],
            ]);

            if (! $this->responseOk($response)) {
                Log::warning('Evolution webhook configuration did not return success.', [
                    'instance_name' => $instanceName,
                    'message' => $this->sanitizeMessage($this->extractMessage($response) ?? 'Unknown webhook configuration failure.'),
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Evolution webhook configuration failed without interrupting activation.', [
                'instance_name' => $instanceName,
                'message' => $this->sanitizeMessage($exception->getMessage()),
            ]);
        }
    }

    private function response(string $instanceName, EvolutionResponse $response): array
    {
        $qrCode = $this->extractQrCode($response);
        $status = $this->extractStatus($response);

        return [
            'success' => $response->ok,
            'provider' => $this->provider(),
            'instance_name' => $instanceName,
            'status' => $status,
            'state' => $this->state($status, $qrCode, $response->ok),
            'qr_code' => $qrCode,
            'message' => $response->message,
            'provider_response' => [
                'ok' => $response->ok,
                'message' => $response->message,
                'data' => $response->data,
            ],
            'last_updated_at' => now()->toJSON(),
        ];
    }

    private function responseIndicatesMissingInstance(mixed $response): bool
    {
        return ! $this->responseOk($response)
            || $this->containsAny($this->extractMessage($response), self::MISSING_INSTANCE_MESSAGES);
    }

    private function exceptionIndicatesMissingInstance(Throwable $exception): bool
    {
        return (int) $exception->getCode() === 404
            || $this->containsAny($exception->getMessage(), self::MISSING_INSTANCE_MESSAGES);
    }

    private function responseIndicatesAlreadyExists(mixed $response): bool
    {
        return $this->containsAny($this->extractMessage($response), self::ALREADY_EXISTS_MESSAGES);
    }

    private function exceptionIndicatesAlreadyExists(Throwable $exception): bool
    {
        return $this->containsAny($exception->getMessage(), self::ALREADY_EXISTS_MESSAGES);
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(?string $message, array $needles): bool
    {
        if ($message === null || $message === '') {
            return false;
        }

        $normalized = mb_strtolower($message);

        foreach ($needles as $needle) {
            if (str_contains($normalized, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function responseOk(mixed $response): bool
    {
        if ($response instanceof EvolutionResponse) {
            return $response->ok;
        }

        if (is_array($response) && array_key_exists('ok', $response)) {
            return (bool) $response['ok'];
        }

        if (is_object($response) && property_exists($response, 'ok')) {
            return (bool) $response->ok;
        }

        if (is_array($response) && array_key_exists('success', $response)) {
            return (bool) $response['success'];
        }

        if (is_object($response) && property_exists($response, 'success')) {
            return (bool) $response->success;
        }

        return true;
    }

    private function extractMessage(mixed $response): ?string
    {
        $message = match (true) {
            $response instanceof EvolutionResponse => $response->message
                ?? data_get($response->data, 'message')
                ?? data_get($response->raw, 'message')
                ?? data_get($response->raw, 'response.message'),
            is_array($response) => data_get($response, 'message') ?? data_get($response, 'response.message'),
            is_object($response) => data_get($response, 'message') ?? data_get($response, 'response.message'),
            default => null,
        };

        if (is_array($message)) {
            $message = json_encode($message);
        }

        return is_scalar($message) && $message !== '' ? (string) $message : null;
    }

    private function extractQrCode(EvolutionResponse $response): ?string
    {
        $value = data_get($response->data, 'qr_code')
            ?? data_get($response->data, 'qrcode')
            ?? data_get($response->data, 'qr')
            ?? data_get($response->data, 'qrCode')
            ?? data_get($response->data, 'base64')
            ?? data_get($response->raw, 'response.qr_code')
            ?? data_get($response->raw, 'response.qrcode')
            ?? data_get($response->raw, 'response.qr')
            ?? data_get($response->raw, 'response.qrCode')
            ?? data_get($response->raw, 'response.base64');

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    private function extractStatus(EvolutionResponse $response): string
    {
        $status = data_get($response->data, 'state')
            ?? data_get($response->data, 'status')
            ?? data_get($response->data, 'instance.state')
            ?? data_get($response->raw, 'response.state')
            ?? data_get($response->raw, 'response.status')
            ?? data_get($response->raw, 'response.instance.state');

        return is_scalar($status) && $status !== '' ? strtolower((string) $status) : ($response->ok ? 'qr_pending' : 'error');
    }

    private function state(string $status, ?string $qrCode, bool $success): string
    {
        if (! $success) {
            return 'error';
        }

        if ($qrCode !== null && ! in_array($status, ['connected', 'open'], true)) {
            return 'qrcode_available';
        }

        return match ($status) {
            'connected', 'open' => 'connected',
            'qrcode_available', 'qr_available', 'qr_pending', 'qrcode_pending' => 'qrcode_available',
            'connecting', 'pending', 'provisioning' => 'connecting',
            'disconnected', 'closed', 'close' => 'disconnected',
            default => 'qrcode_pending',
        };
    }

    private function provider(): string
    {
        $provider = config('messaging.default_provider', 'evolution');

        return is_string($provider) && $provider !== '' ? $provider : 'evolution';
    }

    private function sanitizeMessage(string $message): string
    {
        return substr(preg_replace('/(apikey|api_key|token|authorization|secret)=?[^\\s&]*/i', '$1=[redacted]', $message) ?? $message, 0, 300);
    }
}

