<?php

namespace App\Support\Messages;

use Illuminate\Support\Arr;

class SafeMessageMedia
{
    /**
     * @param  object|array<string, mixed>  $message
     * @return array<string, string>|null
     */
    public static function fromMessage(object|array $message): ?array
    {
        $payload = data_get($message, 'payload');
        $messageType = data_get($message, 'message_type');

        if (! is_array($payload)) {
            return null;
        }

        return self::fromPayload($payload, is_scalar($messageType) ? (string) $messageType : null);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>|null
     */
    public static function fromPayload(array $payload, ?string $messageType = null, bool $allowProviderUrls = false): ?array
    {
        $rawUrl = self::firstString($payload, self::urlKeys());
        $url = self::safeUrl($rawUrl, $allowProviderUrls);
        $base64 = self::safeBase64(self::firstString($payload, self::base64Keys()));
        $hasProviderMedia = $rawUrl !== null && $url === null && self::isProviderUrl($rawUrl);

        if ($url === null && $base64 === null && ! $hasProviderMedia) {
            return null;
        }

        $mimeType = self::firstString($payload, self::mimeTypeKeys());
        $type = self::mediaType($messageType, $mimeType);

        return array_filter([
            'type' => $type,
            'mime_type' => $mimeType,
            'file_name' => self::firstString($payload, self::fileNameKeys()),
            'url' => $url,
            'base64' => $base64,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<int, string>
     */
    private static function urlKeys(): array
    {
        return [
            'media_url',
            'mediaUrl',
            'url',
            'data.media_url',
            'data.mediaUrl',
            'data.url',
            'data.message.media_url',
            'data.message.mediaUrl',
            'data.message.url',
            'data.message.imageMessage.media_url',
            'data.message.imageMessage.mediaUrl',
            'data.message.imageMessage.url',
            'data.message.videoMessage.media_url',
            'data.message.videoMessage.mediaUrl',
            'data.message.videoMessage.url',
            'data.message.documentMessage.media_url',
            'data.message.documentMessage.mediaUrl',
            'data.message.documentMessage.url',
            'message.media_url',
            'message.mediaUrl',
            'message.url',
            'message.imageMessage.media_url',
            'message.imageMessage.mediaUrl',
            'message.imageMessage.url',
            'message.videoMessage.media_url',
            'message.videoMessage.mediaUrl',
            'message.videoMessage.url',
            'message.documentMessage.media_url',
            'message.documentMessage.mediaUrl',
            'message.documentMessage.url',
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function base64Keys(): array
    {
        return [
            'base64',
            'media_base64',
            'mediaBase64',
            'data.base64',
            'data.media_base64',
            'data.mediaBase64',
            'data.message.base64',
            'data.message.media_base64',
            'data.message.mediaBase64',
            'data.message.imageMessage.base64',
            'data.message.imageMessage.jpegThumbnail',
            'data.message.audioMessage.base64',
            'data.message.videoMessage.base64',
            'data.message.videoMessage.jpegThumbnail',
            'data.message.documentMessage.base64',
            'message.base64',
            'message.media_base64',
            'message.mediaBase64',
            'message.imageMessage.base64',
            'message.imageMessage.jpegThumbnail',
            'message.audioMessage.base64',
            'message.videoMessage.base64',
            'message.videoMessage.jpegThumbnail',
            'message.documentMessage.base64',
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function mimeTypeKeys(): array
    {
        return [
            'mime_type',
            'mimeType',
            'mimetype',
            'data.mime_type',
            'data.mimeType',
            'data.mimetype',
            'data.message.mime_type',
            'data.message.mimeType',
            'data.message.mimetype',
            'data.message.imageMessage.mimetype',
            'data.message.audioMessage.mimetype',
            'data.message.videoMessage.mimetype',
            'data.message.documentMessage.mimetype',
            'message.mime_type',
            'message.mimeType',
            'message.mimetype',
            'message.imageMessage.mimetype',
            'message.audioMessage.mimetype',
            'message.videoMessage.mimetype',
            'message.documentMessage.mimetype',
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function fileNameKeys(): array
    {
        return [
            'file_name',
            'fileName',
            'filename',
            'data.file_name',
            'data.fileName',
            'data.filename',
            'data.message.file_name',
            'data.message.fileName',
            'data.message.filename',
            'data.message.documentMessage.fileName',
            'message.file_name',
            'message.fileName',
            'message.filename',
            'message.documentMessage.fileName',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private static function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($payload, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private static function safeUrl(?string $url, bool $allowProviderUrls = false): ?string
    {
        if ($url === null) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $url) === 1) {
            $host = parse_url($url, PHP_URL_HOST);

            return is_string($host) && strtolower($host) === 'mmg.whatsapp.net' && ! $allowProviderUrls
                ? null
                : $url;
        }

        if (preg_match('/^data:(image|audio|video|application)\/[a-z0-9.+-]+;base64,/i', $url) === 1) {
            return $url;
        }

        return null;
    }

    private static function isProviderUrl(string $url): bool
    {
        if (preg_match('/^https?:\/\//i', $url) !== 1) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && strtolower($host) === 'mmg.whatsapp.net';
    }

    private static function safeBase64(?string $base64): ?string
    {
        if ($base64 === null) {
            return null;
        }

        if (self::safeUrl($base64, false) !== null) {
            return null;
        }

        $normalized = preg_replace('/\s+/', '', $base64);

        return is_string($normalized) && preg_match('/^[A-Za-z0-9+\/=]+$/', $normalized) === 1
            ? $normalized
            : null;
    }

    private static function mediaType(?string $messageType, ?string $mimeType): string
    {
        $messageType = strtolower((string) $messageType);

        if (in_array($messageType, ['image', 'audio', 'video', 'document'], true)) {
            return $messageType;
        }

        $mimeType = strtolower((string) $mimeType);

        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'audio/') => 'audio',
            str_starts_with($mimeType, 'video/') => 'video',
            default => 'document',
        };
    }
}
