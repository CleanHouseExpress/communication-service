<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = config('communication.service_token');
        $providedToken = $this->extractToken($request);

        if ($providedToken === null) {
            return response()->json(['message' => 'Service token is required.'], 401);
        }

        if (! is_string($configuredToken) || $configuredToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            return response()->json(['message' => 'Service token is invalid.'], 403);
        }

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $headerToken = $request->headers->get('X-Service-Token');

        if (is_string($headerToken) && $headerToken !== '') {
            return $headerToken;
        }

        $authorization = $request->headers->get('Authorization');

        if (is_string($authorization) && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
