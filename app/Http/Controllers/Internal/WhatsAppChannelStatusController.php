<?php

namespace App\Http\Controllers\Internal;

use App\Contracts\Messaging\ChannelStatusCheckerInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppChannelStatusController extends Controller
{
    public function __invoke(Request $request, ChannelStatusCheckerInterface $checker): JsonResponse
    {
        $validated = $request->validate([
            'instance_name' => ['required', 'string', 'max:128'],
        ]);

        $result = $checker->check($validated['instance_name']);

        return response()->json($result->toArray(), $result->success ? 200 : 502);
    }
}
