<?php

namespace App\DTO\Messaging;

class MessagePayload
{
    public function __construct(
        public readonly string $instanceName,
        public readonly string $number,
        public readonly ?string $message = null,
        public readonly ?string $mediaUrl = null,
        public readonly ?string $caption = null,
        public readonly ?string $fileName = null,
    ) {
    }
}
