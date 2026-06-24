<?php

namespace App\DTO\Agents;

class AgentResponseData
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $responseText,
        public readonly bool $shouldReply,
        public readonly bool $shouldHandoff,
        public readonly array $rawResponse = [],
        public readonly ?string $error = null,
    ) {}

    public static function skipped(string $reason): self
    {
        return new self(
            success: true,
            responseText: null,
            shouldReply: false,
            shouldHandoff: false,
            rawResponse: [
                'status' => 'skipped',
                'reason' => $reason,
            ],
        );
    }

    public static function failure(string $error, array $rawResponse = []): self
    {
        return new self(
            success: false,
            responseText: null,
            shouldReply: false,
            shouldHandoff: false,
            rawResponse: $rawResponse,
            error: $error,
        );
    }

    public static function fromArray(array $data): self
    {
        $responseText = $data['response_text']
            ?? $data['text']
            ?? $data['message']
            ?? null;

        return new self(
            success: (bool) ($data['success'] ?? true),
            responseText: is_scalar($responseText) ? (string) $responseText : null,
            shouldReply: (bool) ($data['should_reply'] ?? ($responseText !== null && $responseText !== '')),
            shouldHandoff: (bool) ($data['should_handoff'] ?? false),
            rawResponse: $data,
            error: isset($data['error']) && is_scalar($data['error']) ? (string) $data['error'] : null,
        );
    }
}
