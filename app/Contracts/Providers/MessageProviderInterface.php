<?php

namespace App\Contracts\Providers;

interface MessageProviderInterface
{
    public function sendText(array $payload): array;

    public function sendMedia(array $payload): array;

    public function parseWebhook(array $payload): array;
}
