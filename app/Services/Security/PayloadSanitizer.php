<?php

namespace App\Services\Security;

class PayloadSanitizer
{
    private const SENSITIVE_KEYS = [
        'token',
        'service_token',
        'client_token',
        'authorization',
        'password',
        'secret',
    ];

    public function sanitize(array $payload): array
    {
        return collect($payload)
            ->mapWithKeys(fn ($value, $key) => [$key => $this->sanitizeValue((string) $key, $value)])
            ->all();
    }

    private function sanitizeValue(string $key, mixed $value): mixed
    {
        if ($this->isSensitiveKey($key)) {
            return '[redacted]';
        }

        if (is_array($value)) {
            return $this->sanitize($value);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalizedKey = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (str_contains($normalizedKey, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }
}
