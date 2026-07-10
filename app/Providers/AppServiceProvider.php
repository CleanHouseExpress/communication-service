<?php

namespace App\Providers;

use App\Contracts\Messaging\ChannelStatusCheckerInterface;
use App\Contracts\Messaging\MessageSenderInterface;
use App\Services\Messaging\WhatsAppChannelStatusChecker;
use App\Services\Messaging\WhatsAppMessageSender;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentTenantConnection::class);
        $this->app->bind(MessageSenderInterface::class, WhatsAppMessageSender::class);
        $this->app->bind(ChannelStatusCheckerInterface::class, WhatsAppChannelStatusChecker::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('internal-health', function (Request $request) {
        return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('internal-api', function (Request $request) {
            $identifier = $request->bearerToken() ?: $request->ip();

            return Limit::perMinute(120)->by(hash('sha256', $identifier));
        });
    }
}
