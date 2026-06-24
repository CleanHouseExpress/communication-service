<?php

namespace App\Support\Normalization;

use App\DTO\Messages\InboundMessageData;
use App\Enums\MessageType;
use App\Enums\ProviderType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class ZapiWebhookNormalizer
{
    public function normalize(array $payload, ?string $tenantId = null, ?string $channelId = null): InboundMessageData
    {
        $externalMessageId = $this->firstString($payload, ['messageId', 'message_id', 'id']);
        $externalEventId = $this->firstString($payload, ['eventId', 'event_id', 'webhookId', 'webhook_id']) ?? $externalMessageId;
        $phone = $this->firstString($payload, ['phone', 'from', 'sender', 'participantPhone', 'participant_phone']);
        $contactName = $this->firstString($payload, ['senderName', 'sender_name', 'name', 'pushName', 'chatName']);
        $messageType = $this->detectMessageType($payload);

        return new InboundMessageData(
            provider: ProviderType::Zapi,
            tenantId: $tenantId,
            channelId: $channelId,
            externalEventId: $externalEventId,
            externalMessageId: $externalMessageId,
            externalContactId: $this->normalizeExternalContactId($phone),
            contactName: $contactName,
            contactPhone: $this->normalizePhone($phone),
            messageType: $messageType,
            text: $this->extractText($payload),
            occurredAt: $this->extractOccurredAt($payload),
            rawPayload: $payload,
        );
    }

    public function extractExternalMessageId(array $payload): ?string
    {
        return $this->firstString($payload, ['messageId', 'message_id', 'id']);
    }

    public function extractExternalEventId(array $payload): ?string
    {
        return $this->firstString($payload, ['eventId', 'event_id', 'webhookId', 'webhook_id']) ?? $this->extractExternalMessageId($payload);
    }

    private function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($payload, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function detectMessageType(array $payload): MessageType
    {
        if ($this->extractText($payload) !== null) {
            return MessageType::Text;
        }

        foreach ([
            'image' => MessageType::Image,
            'audio' => MessageType::Audio,
            'video' => MessageType::Video,
            'document' => MessageType::Document,
        ] as $key => $type) {
            if (! empty($payload[$key])) {
                return $type;
            }
        }

        return MessageType::Unknown;
    }

    private function extractText(array $payload): ?string
    {
        foreach (['text.message', 'text', 'message', 'body', 'caption', 'image.caption', 'document.caption', 'audio.transcription'] as $key) {
            $value = Arr::get($payload, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function extractOccurredAt(array $payload): ?CarbonImmutable
    {
        $value = $this->firstString($payload, ['timestamp', 'createdAt', 'created_at', 'messageTimestamp']);

        if ($value === null) {
            return null;
        }

        try {
            return is_numeric($value)
                ? CarbonImmutable::createFromTimestamp((int) $value)
                : CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeExternalContactId(?string $value): string
    {
        $normalizedPhone = $this->normalizePhone($value);

        return $normalizedPhone ?? trim((string) $value);
    }

    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        return $digits !== '' ? $digits : null;
    }
}
