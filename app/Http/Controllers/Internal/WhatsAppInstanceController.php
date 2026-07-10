<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\Messaging\WhatsAppInstanceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppInstanceController extends Controller
{
    public function activate(Request $request, WhatsAppInstanceManager $instances): JsonResponse
    {
        $validated = $this->validated($request);
        $result = $instances->activate($validated['instance_name']);

        return response()->json($result, $result['success'] ? 200 : 502);
    }

    public function refreshQrCode(Request $request, WhatsAppInstanceManager $instances): JsonResponse
    {
        $validated = $this->validated($request);
        $result = $instances->refreshQrCode($validated['instance_name']);

        return response()->json($result, $result['success'] ? 200 : 502);
    }

    /**
     * @return array{instance_name: string}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'tenant_id' => ['nullable', 'string', 'max:64'],
            'channel_id' => ['nullable', 'string', 'max:128'],
            'instance_name' => ['required', 'string', 'max:128'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'provider_instance_id' => ['nullable', 'string', 'max:128'],
        ]);
    }
}
