<?php

namespace App\Support\Security;

class AgentPromptGuard
{
    private const MAX_TEXT_LENGTH = 4000;

    private const PATTERNS = [
        'ignore previous instructions',
        'system prompt',
        'developer message',
        'reveal instructions',
        'bypass',
    ];

    public function inspect(?string $text): array
    {
        $normalizedText = strtolower((string) $text);
        $reasons = [];

        foreach (self::PATTERNS as $pattern) {
            if (str_contains($normalizedText, $pattern)) {
                $reasons[] = $pattern;
            }
        }

        return [
            'text' => $this->limitText($text),
            'metadata' => [
                'user_message_role' => 'external_user_message',
                'prompt_injection_suspected' => $reasons !== [],
                'prompt_injection_reasons' => $reasons,
                'text_truncated_for_agent' => is_string($text) && strlen($text) > self::MAX_TEXT_LENGTH,
            ],
        ];
    }

    private function limitText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        if (strlen($text) <= self::MAX_TEXT_LENGTH) {
            return $text;
        }

        return substr($text, 0, self::MAX_TEXT_LENGTH);
    }
}
