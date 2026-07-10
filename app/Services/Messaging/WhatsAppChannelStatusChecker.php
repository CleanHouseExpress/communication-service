<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\ChannelStatusCheckerInterface;
use App\DTO\Messaging\ChannelStatusResult;
use Clin\MessagingSdk\DTO\EvolutionResponse;
use Clin\MessagingSdk\MessagingClient;
use Throwable;

class WhatsAppChannelStatusChecker implements ChannelStatusCheckerInterface
{
    public function __construct(private readonly MessagingClient $messaging)
    {
    }

    public function check(string $instanceName): ChannelStatusResult
    {
        try {
            $response = $this->messaging->instances()->connectionState($instanceName);

            return new ChannelStatusResult(
                success: $response->ok,
                provider: $this->provider(),
                instanceName: $instanceName,
                status: $this->extractStatus($response),
                message: $response->message,
                providerResponse: [
                    'ok' => $response->ok,
                    'message' => $response->message,
                    'data' => $response->data,
                ],
            );
        } catch (Throwable $exception) {
            return new ChannelStatusResult(
                success: false,
                provider: $this->provider(),
                instanceName: $instanceName,
                status: 'unknown',
                message: $this->sanitizeMessage($exception->getMessage()),
            );
        }
    }

    private function extractStatus(EvolutionResponse $response): ?string
    {
        $status = data_get($response->data, 'state')
            ?? data_get($response->data, 'instance.state')
            ?? data_get($response->data, 'status')
            ?? data_get($response->raw, 'instance.state')
            ?? data_get($response->raw, 'state')
            ?? data_get($response->raw, 'status');

        return is_scalar($status) ? (string) $status : ($response->ok ? 'connected' : 'unknown');
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
