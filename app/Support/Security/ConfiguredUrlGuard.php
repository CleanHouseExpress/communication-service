<?php

namespace App\Support\Security;

class ConfiguredUrlGuard
{
    public function validate(?string $url, string $label): ?string
    {
        if (! is_string($url) || $url === '') {
            return "{$label} URL is not configured.";
        }

        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true) || ! is_string($host) || $host === '') {
            return "{$label} URL is invalid.";
        }

        if (app()->environment('production') && strtolower($scheme) !== 'https') {
            return "{$label} URL must use HTTPS in production.";
        }

        if (app()->environment('production') && $this->isPrivateHost($host)) {
            return "{$label} URL host is not allowed in production.";
        }

        return null;
    }

    private function isPrivateHost(string $host): bool
    {
        $normalizedHost = strtolower(trim($host, '[]'));

        if ($normalizedHost === 'localhost' || str_ends_with($normalizedHost, '.localhost')) {
            return true;
        }

        if (! filter_var($normalizedHost, FILTER_VALIDATE_IP)) {
            return false;
        }

        return ! filter_var($normalizedHost, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
