<?php

namespace App\DTO\Webhooks;

class ProviderWebhookData
{
    public function __construct(
        public readonly string $provider,
        public readonly string $event,
        public readonly array $payload,
        public readonly array $headers = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            provider: $data['provider'] ?? 'unknown',
            event: $data['event'] ?? 'message.received',
            payload: $data['payload'] ?? $data,
            headers: $data['headers'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'event' => $this->event,
            'payload' => $this->payload,
            'headers' => $this->headers,
        ];
    }
}
