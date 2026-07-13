<?php

namespace App\Http\Controllers\Providers;

use App\Actions\Messages\ProcessInboundMessageAction;
use App\Http\Controllers\Controller;
use App\Support\Normalization\EvolutionWebhookNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $messageData = $normalizer->normalize($request->all());

        if ($messageData === null) {
            return response()->json([
                'accepted' => true,
                'provider' => 'evolution',
                'event' => $event,
                'processed' => false,
            ]);
        }

        $result = $processInboundMessage->handle($messageData);

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
}
