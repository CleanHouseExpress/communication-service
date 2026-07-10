<?php

namespace App\Providers;

use App\Contracts\Messaging\ChannelStatusCheckerInterface;
use App\Contracts\Messaging\MessageSenderInterface;
use App\Services\Messaging\WhatsAppChannelStatusChecker;
use App\Services\Messaging\WhatsAppMessageSender;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Support\ServiceProvider;

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
        //
    }
}
