<?php

namespace App\Support\Normalization;

use App\DTO\Messages\InboundMessageData;
use App\Enums\MessageType;
use App\Enums\ProviderType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class EvolutionWebhookNormalizer
{
    public function normalize(array $payload): ?InboundMessageData
    {
        $data = $this->messageData($payload);

        if ((bool) Arr::get($data, 'key.fromMe', false)) {
            return null;
        }

        $remoteJid = $this->firstString($data, ['key.remoteJid', 'remoteJid', 'from', 'sender']);

        if ($this->isGroupJid($remoteJid)) {
            return null;
        }

        $participant = $this->firstString($data, ['key.participant', 'participant']);
        $contactPhone = $this->normalizePhone($participant ?? $remoteJid);

        if ($contactPhone === null) {
            return null;
        }

        $externalMessageId = $this->firstString($data, ['key.id', 'id', 'messageId', 'message_id']);
        $messageType = $this->detectMessageType($data);
        $text = $this->extractText($data);

        if ($messageType === MessageType::Unknown && $text === null) {
            return null;
        }

        return new InboundMessageData(
            provider: ProviderType::WhatsApp,
            tenantId: $this->tenantId($payload, $data),
            channelId: $this->firstString($payload, ['channel_id', 'channelId'])
                ?? $this->firstString($data, ['channel_id', 'channelId'])
                ?? $this->firstString($payload, ['instance', 'instance_name', 'instanceName'])
                ?? $this->firstString($data, ['instance', 'instance_name', 'instanceName']),
            externalEventId: $this->firstString($payload, ['event_id', 'eventId', 'id']) ?? $externalMessageId,
            externalMessageId: $externalMessageId,
            externalContactId: $contactPhone,
            contactName: $this->firstString($data, ['pushName', 'push_name', 'senderName', 'sender_name', 'name']),
            contactPhone: $contactPhone,
            messageType: $messageType,
            text: $text,
            occurredAt: $this->occurredAt($data),
            rawPayload: $payload,
        );
    }

    public function extractExternalMessageId(array $payload): ?string
    {
        return $this->firstString($this->messageData($payload), ['key.id', 'id', 'messageId', 'message_id']);
    }

    private function messageData(array $payload): array
    {
        $data = Arr::get($payload, 'data', $payload);

        if (is_array($data) && array_is_list($data)) {
            $data = $data[0] ?? [];
        }

        return is_array($data) ? $data : [];
    }

    private function tenantId(array $payload, array $data): ?string
    {
        $tenantId = $this->firstString($payload, ['tenant_id', 'tenant.id', 'orchestra_tenant_id'])
            ?? $this->firstString($data, ['tenant_id', 'tenant.id', 'orchestra_tenant_id']);

        if ($tenantId !== null) {
            return $tenantId;
        }

        $instanceName = $this->firstString($payload, ['instance', 'instance_name', 'instanceName'])
            ?? $this->firstString($data, ['instance', 'instance_name', 'instanceName']);

        if ($instanceName !== null && preg_match('/-(\d+)-whatsapp$/', $instanceName, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function detectMessageType(array $data): MessageType
    {
        $message = Arr::get($data, 'message', []);

        if ($this->extractText($data) !== null) {
            return MessageType::Text;
        }

        return match (true) {
            is_array($message) && Arr::has($message, 'imageMessage') => MessageType::Image,
            is_array($message) && Arr::has($message, 'audioMessage') => MessageType::Audio,
            is_array($message) && Arr::has($message, 'videoMessage') => MessageType::Video,
            is_array($message) && Arr::has($message, 'documentMessage') => MessageType::Document,
            default => MessageType::Unknown,
        };
    }

    private function extractText(array $data): ?string
    {
        foreach ([
            'message.conversation',
            'message.extendedTextMessage.text',
            'message.imageMessage.caption',
            'message.videoMessage.caption',
            'message.documentMessage.caption',
            'text',
            'body',
            'messageText',
        ] as $key) {
            $value = Arr::get($data, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function occurredAt(array $data): ?CarbonImmutable
    {
        $value = $this->firstString($data, ['messageTimestamp', 'timestamp', 'createdAt', 'created_at']);

        if ($value === null) {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $timestamp = (int) $value;

                return CarbonImmutable::createFromTimestamp($timestamp > 9999999999 ? (int) floor($timestamp / 1000) : $timestamp);
            }

            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
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

    private function isGroupJid(?string $jid): bool
    {
        return $jid !== null && str_ends_with(strtolower($jid), '@g.us');
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
