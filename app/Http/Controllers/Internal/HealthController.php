<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $databaseOk = $this->databaseOk();

        return response()->json([
            'status' => $databaseOk ? 'ok' : 'degraded',
            'authenticated' => true,
            'app' => [
                'ok' => true,
                'service' => config('communication.service_name'),
                'environment' => config('communication.environment'),
            ],
            'database' => [
                'ok' => $databaseOk,
            ],
            'config' => [
                'ok' => $this->configOk(),
                'service_token_configured' => $this->hasStringConfig('communication.service_token'),
                'provider_webhook_configured' => $this->providerWebhookConfigured(),
            ],
            'agent' => [
                'enabled' => (bool) config('communication.agent.enabled', false),
                'fake' => (bool) config('communication.agent.fake', true),
            ],
            'zapi' => [
                'enabled' => (bool) config('communication.providers.zapi.enabled', false),
                'fake' => (bool) config('communication.providers.zapi.fake', true),
            ],
            'timestamp' => now()->toIso8601String(),
        ], $databaseOk ? 200 : 503);
    }

    private function databaseOk(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function configOk(): bool
    {
        return $this->hasStringConfig('communication.service_name')
            && $this->hasStringConfig('communication.default_provider');
    }

    private function providerWebhookConfigured(): bool
    {
        if (app()->environment(['local', 'testing']) || ! config('communication.providers.zapi.enabled')) {
            return true;
        }

        return $this->hasStringConfig('communication.providers.zapi.webhook_secret');
    }

    private function hasStringConfig(string $key): bool
    {
        $value = config($key);

        return is_string($value) && $value !== '';
    }
}
