<?php

namespace App\DTO\Providers;

class ZapiSendResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId,
        public readonly array $response,
        public readonly ?string $error = null,
    ) {}

    public static function success(?string $providerMessageId, array $response): self
    {
        return new self(true, $providerMessageId, $response);
    }

    public static function failure(string $error, array $response = []): self
    {
        return new self(false, null, $response, $error);
    }
}
