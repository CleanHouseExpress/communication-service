<?php

use Illuminate\Support\Facades\Route;

Route::middleware('throttle:internal-api')->group(function (): void {
    Route::get('/health', fn () => [
        'status' => 'ok',
        'service' => config('communication.service_name'),
    ]);

    Route::get('/version', fn () => [
        'service' => config('communication.service_name'),
        'version' => '0.1.0',
    ]);

    Route::get('/internal/health', fn () => [
        'status' => 'ok',
        'authenticated' => true,
    ])->middleware('service.token');
});
