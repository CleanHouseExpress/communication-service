<?php

namespace App\Http\Controllers\Providers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvolutionWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'accepted' => true,
            'provider' => 'evolution',
            'event' => $request->input('event', 'webhook'),
        ]);
    }

    public function messages(Request $request): JsonResponse
    {
        return response()->json([
            'accepted' => true,
            'provider' => 'evolution',
            'event' => 'messages',
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'accepted' => true,
            'provider' => 'evolution',
            'event' => 'message-status',
        ]);
    }
}
