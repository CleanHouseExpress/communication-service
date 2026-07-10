<?php

namespace App\Services\Messaging;

use Clin\MessagingSdk\DTO\EvolutionResponse;
use Clin\MessagingSdk\Exceptions\RequestException;
use Clin\MessagingSdk\MessagingClient;
use Throwable;

class WhatsAppInstanceManager
{
    public function __construct(private readonly MessagingClient $messaging)
    {
    }

    public function activate(string $instanceName): array
    {
        $this->ensureInstanceExists($instanceName);

        return $this->connect($instanceName);
    }

    public function refreshQrCode(string $instanceName): array
    {
        return $this->connect($instanceName);
    }

    private function ensureInstanceExists(string $instanceName): void
    {
        try {
            $this->messaging->instances()->fetch($instanceName);

            return;
        } catch (RequestException $exception) {
            if ((int) $exception->getCode() !== 404) {
                throw $exception;
            }
        }

        try {
            $this->messaging->instances()->create($instanceName);
        } catch (RequestException $exception) {
            if (! str_contains(strtolower($exception->getMessage()), 'exist')) {
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
