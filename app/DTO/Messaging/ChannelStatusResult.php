<?php

namespace App\DTO\Messaging;

class ChannelStatusResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $provider,
        public readonly string $instanceName,
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
            'instance_name' => $this->instanceName,
            'status' => $this->status,
            'message' => $this->message,
            'provider_response' => $this->providerResponse,
        ];
    }
}
