<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use App\Http\Middleware\ApiSecurityHeaders;
use App\Http\Middleware\EnsureServiceToken;
use App\Http\Middleware\VerifyProviderWebhookSignature;
use App\Support\Tenancy\TenantResolutionException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            RateLimiter::for('internal-health', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
            RateLimiter::for('internal-api', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
            RateLimiter::for('provider-webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
            RateLimiter::for('agent-callbacks', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        },
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            ApiSecurityHeaders::class,
        ]);

        $middleware->alias([
            'service.token' => EnsureServiceToken::class,
            'provider.webhook.signature' => VerifyProviderWebhookSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (TenantResolutionException $exception, Request $request) {
            return response()->json([
                'message' => 'Communication tenant is not active or was not found.',
            ], 422);
        });
    })->create();
