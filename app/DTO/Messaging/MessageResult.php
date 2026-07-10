<?php

namespace App\DTO\Messaging;

class MessageResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $provider,
        public readonly string $type,
        public readonly string $instanceName,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $status = null,
        public readonly ?string $message = null,
        public readonly array $providerResponse = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'provider' => $this->provider,
            'type' => $this->type,
            'instance_name' => $this->instanceName,
            'provider_message_id' => $this->providerMessageId,
            'status' => $this->status,
            'message' => $this->message,
            'provider_response' => $this->providerResponse,
        ];
    }
}
