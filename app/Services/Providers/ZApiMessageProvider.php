<?php

namespace App\Services\Providers;

use App\Contracts\Providers\MessageProviderInterface;
use RuntimeException;

class ZApiMessageProvider implements MessageProviderInterface
{
    public function sendText(array $payload): array
    {
        return $this->fakeResponse('text', $payload);
    }

    public function sendMedia(array $payload): array
    {
        return $this->fakeResponse('media', $payload);
    }

    public function parseWebhook(array $payload): array
    {
        return [
            'provider' => 'zapi',
            'payload' => $payload,
        ];
    }

    private function fakeResponse(string $type, array $payload): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Z-API integration is not implemented yet.');
        }

        return [
            'provider' => 'zapi',
            'type' => $type,
            'status' => 'queued',
            'message_id' => 'fake-'.uniqid('', true),
            'payload' => $payload,
        ];
    }
}
