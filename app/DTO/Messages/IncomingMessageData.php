<?php

namespace App\DTO\Messages;

class IncomingMessageData
{
    public function __construct(
        public readonly ?string $provider,
        public readonly ?string $messageId,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly ?string $type,
        public readonly array $payload,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            provider: $data['provider'] ?? null,
            messageId: $data['message_id'] ?? null,
            from: $data['from'] ?? null,
            to: $data['to'] ?? null,
            type: $data['type'] ?? null,
            payload: $data['payload'] ?? $data,
        );
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'message_id' => $this->messageId,
            'from' => $this->from,
            'to' => $this->to,
            'type' => $this->type,
            'payload' => $this->payload,
        ];
    }
}
