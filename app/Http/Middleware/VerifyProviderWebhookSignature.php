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
        if (! config('communication.providers.zapi.enabled')) {
            return true;
        }

        if (app()->environment(['local', 'testing']) && (bool) config('communication.providers.zapi.allow_unsigned_webhook_local', true)) {
            return true;
        }

        $secret = config('communication.providers.zapi.webhook_secret');
        $signatureHeader = config('communication.providers.zapi.webhook_signature_header', 'X-Zapi-Signature');

        if (! is_string($secret) || $secret === '' || ! is_string($signatureHeader) || $signatureHeader === '') {
            return false;
        }

        $providedSignature = $request->headers->get($signatureHeader);

        if (! is_string($providedSignature) || $providedSignature === '') {
            return false;
        }

        $expectedHmac = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($secret, $providedSignature)
            || hash_equals($expectedHmac, $providedSignature);
    }
}
