<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyProviderWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isValidSignature($request)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 403);
        }

        return $next($request);
    }

    private function isValidSignature(Request $request): bool
    {
        // Extension point for provider-specific signature checks.
        return app()->environment(['local', 'testing']) || ! config('communication.providers.zapi.enabled');
    }
}
