<?php

return [
    'default_provider' => env('MESSAGING_PROVIDER', 'evolution'),

    'providers' => [
        'evolution' => [
            'base_url' => env('EVOLUTION_BASE_URL', ''),
            'api_key' => env('EVOLUTION_API_KEY', ''),
            'timeout' => (int) env('EVOLUTION_TIMEOUT', 30),
            'log_requests' => filter_var(env('EVOLUTION_LOG_REQUESTS', false), FILTER_VALIDATE_BOOL),
            'webhook_url' => env('EVOLUTION_WEBHOOK_URL', env('APP_URL') ? rtrim((string) env('APP_URL'), '/').'/api/webhooks/evolution' : null),
            'webhook_events' => array_filter(array_map('trim', explode(',', (string) env('EVOLUTION_WEBHOOK_EVENTS', 'MESSAGES_UPSERT,MESSAGES_UPDATE,CONNECTION_UPDATE')))),
        ],
    ],
];
