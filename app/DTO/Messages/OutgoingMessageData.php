<?php

namespace App\DTO\Messages;

class OutgoingMessageData
{
    public function __construct(
        public readonly ?string $provider,
        public readonly string $to,
        public readonly string $type,
        public readonly array $content,
        public readonly array $metadata = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            provider: $data['provider'] ?? null,
            to: $data['to'] ?? '',
            type: $data['type'] ?? 'text',
            content: $data['content'] ?? [],
            metadata: $data['metadata'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'to' => $this->to,
            'type' => $this->type,
            'content' => $this->content,
            'metadata' => $this->metadata,
        ];
    }
}
