<?php

namespace App\Http\Controllers\Providers;

use App\Actions\Messages\ProcessInboundMessageAction;
use App\Http\Controllers\Controller;
use App\Support\Normalization\EvolutionWebhookNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class EvolutionWebhookController extends Controller
{
    public function __invoke(Request $request, EvolutionWebhookNormalizer $normalizer, ProcessInboundMessageAction $processInboundMessage): JsonResponse
    {
        return $this->processMessageWebhook($request, $normalizer, $processInboundMessage, (string) $request->input('event', 'webhook'));
    }

    public function messages(Request $request, EvolutionWebhookNormalizer $normalizer, ProcessInboundMessageAction $processInboundMessage): JsonResponse
    {
        return $this->processMessageWebhook($request, $normalizer, $processInboundMessage, 'messages');
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'accepted' => true,
            'provider' => 'evolution',
            'event' => 'message-status',
        ]);
    }

    private function processMessageWebhook(
        Request $request,
        EvolutionWebhookNormalizer $normalizer,
        ProcessInboundMessageAction $processInboundMessage,
        string $event,
    ): JsonResponse {
        Log::info('Evolution webhook received.', [
            'event' => $event,
            'instance' => $this->safeString($request->input('instance') ?? $request->input('instance_name') ?? $request->input('instanceName')),
        ]);

        $messageData = $normalizer->normalize($request->all());

        if ($messageData === null) {
            Log::info('Evolution webhook skipped.', [
                'event' => $event,
                'instance' => $this->safeString($request->input('instance') ?? $request->input('instance_name') ?? $request->input('instanceName')),
                'reason' => 'not_inbound_message',
            ]);

            return response()->json([
                'accepted' => true,
                'provider' => 'evolution',
                'event' => $event,
                'processed' => false,
            ]);
        }

        try {
            $result = $processInboundMessage->handle($messageData);
        } catch (Throwable $exception) {
            Log::error('Evolution webhook processing failed.', [
                'event' => $event,
                'tenant_id' => $messageData->tenantId,
                'external_message_id' => $messageData->externalMessageId,
                'error' => $this->safeString($exception->getMessage()),
            ]);

            throw $exception;
        }

        Log::info('Evolution webhook processed.', [
            'event' => $event,
            'tenant_id' => $messageData->tenantId,
            'external_message_id' => $messageData->externalMessageId,
            'message_created' => (bool) ($result['message_created'] ?? false),
            'conversation_id' => (string) ($result['conversation']->id ?? ''),
        ]);

        return response()->json([
            'accepted' => true,
            'provider' => 'evolution',
            'event' => $event,
            'processed' => true,
            'message_created' => (bool) ($result['message_created'] ?? false),
            'conversation_id' => (string) ($result['conversation']->id ?? ''),
            'message_id' => (string) ($result['message']->id ?? ''),
        ]);
    }

    private function safeString(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return substr(preg_replace('/(apikey|api_key|token|authorization|secret|pairing|qr|qrcode|base64)=?[^\\s&]*/i', '$1=[redacted]', (string) $value) ?? (string) $value, 0, 300);
    }
}
