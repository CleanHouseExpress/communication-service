<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\MessageSenderInterface;
use App\DTO\Messaging\MessagePayload;
use App\DTO\Messaging\MessageResult;
use Clin\MessagingSdk\DTO\EvolutionResponse;
use Clin\MessagingSdk\MessagingClient;
use Throwable;

class WhatsAppMessageSender implements MessageSenderInterface
{
    public function __construct(private readonly MessagingClient $messaging)
    {
    }

    public function sendText(MessagePayload $payload): MessageResult
    {
        return $this->send('text', $payload, fn () => $this->messaging->messages()->sendText(
            instanceName: $payload->instanceName,
            number: $payload->number,
            text: (string) $payload->message,
        ));
    }

    public function sendImage(MessagePayload $payload): MessageResult
    {
        return $this->send('image', $payload, fn () => $this->messaging->messages()->sendImage(
            instanceName: $payload->instanceName,
            number: $payload->number,
            mediaUrl: (string) $payload->mediaUrl,
            caption: $payload->caption,
        ));
    }

    public function sendDocument(MessagePayload $payload): MessageResult
    {
        return $this->send('document', $payload, fn () => $this->messaging->messages()->sendDocument(
            instanceName: $payload->instanceName,
            number: $payload->number,
            mediaUrl: (string) $payload->mediaUrl,
            caption: $payload->caption,
            fileName: $payload->fileName,
        ));
    }

    public function sendAudio(MessagePayload $payload): MessageResult
    {
        return $this->send('audio', $payload, fn () => $this->messaging->messages()->sendAudio(
            instanceName: $payload->instanceName,
            number: $payload->number,
            mediaUrl: (string) $payload->mediaUrl,
        ));
    }

    private function send(string $type, MessagePayload $payload, callable $callback): MessageResult
    {
        try {
            /** @var EvolutionResponse $response */
            $response = $callback();

            return new MessageResult(
                success: $response->ok,
                provider: $this->provider(),
                type: $type,
                instanceName: $payload->instanceName,
                providerMessageId: $this->extractMessageId($response),
                status: $response->ok ? 'sent' : 'failed',
                message: $response->message,
                providerResponse: $this->normalizeProviderResponse($response),
            );
        } catch (Throwable $exception) {
            return new MessageResult(
                success: false,
                provider: $this->provider(),
                type: $type,
                instanceName: $payload->instanceName,
                status: 'failed',
                message: $this->sanitizeMessage($exception->getMessage()),
            );
        }
    }

    private function provider(): string
    {
        $provider = config('messaging.default_provider', 'evolution');

        return is_string($provider) && $provider !== '' ? $provider : 'evolution';
    }

    private function extractMessageId(EvolutionResponse $response): ?string
    {
        $id = data_get($response->data, 'key.id')
            ?? data_get($response->data, 'id')
            ?? data_get($response->raw, 'key.id')
            ?? data_get($response->raw, 'response.key.id')
            ?? data_get($response->raw, 'response.messageId')
            ?? data_get($response->raw, 'messageId');

        return is_scalar($id) ? (string) $id : null;
    }

    private function normalizeProviderResponse(EvolutionResponse $response): array
    {
        return [
            'ok' => $response->ok,
            'message' => $response->message,
            'data' => $response->data,
        ];
    }

    private function sanitizeMessage(string $message): string
    {
        return substr(preg_replace('/(apikey|api_key|token|authorization|secret)=?[^\\s&]*/i', '$1=[redacted]', $message) ?? $message, 0, 300);
    }
}
