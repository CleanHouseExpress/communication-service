<?php

return [
    'service_name' => env('COMMUNICATION_GATEWAY_SERVICE_NAME', 'communication-gateway'),

    'service_token' => env('COMMUNICATION_GATEWAY_SERVICE_TOKEN'),

    'default_provider' => env('COMMUNICATION_DEFAULT_PROVIDER', 'zapi'),

    'providers' => [
        'zapi' => [
            'enabled' => env('COMMUNICATION_ZAPI_ENABLED', false),
            'base_url' => env('COMMUNICATION_ZAPI_BASE_URL'),
            'instance_id' => env('COMMUNICATION_ZAPI_INSTANCE_ID'),
            'token' => env('COMMUNICATION_ZAPI_TOKEN'),
            'client_token' => env('COMMUNICATION_ZAPI_CLIENT_TOKEN'),
        ],
    ],

    'orchestra' => [
        'base_url' => env('ORCHESTRA_API_URL'),
        'service_token' => env('ORCHESTRA_SERVICE_TOKEN'),
    ],

    'agent' => [
        'enabled' => env('COMMUNICATION_AGENT_ENABLED', false),
        'provider' => env('COMMUNICATION_AGENT_PROVIDER', 'n8n'),
        'n8n_webhook_url' => env('COMMUNICATION_N8N_WEBHOOK_URL'),
        'callback_token' => env('COMMUNICATION_AGENT_CALLBACK_TOKEN'),
    ],
];
