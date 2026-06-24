<?php

return [
    'service_name' => env('COMMUNICATION_GATEWAY_SERVICE_NAME', 'communication-gateway'),

    'environment' => env('APP_ENV', 'production'),

    'service_token' => env('COMMUNICATION_GATEWAY_SERVICE_TOKEN'),

    'default_provider' => env('COMMUNICATION_DEFAULT_PROVIDER', 'zapi'),

    'tenancy' => [
        'enforce' => env('COMMUNICATION_TENANCY_ENFORCE', false),
    ],

    'providers' => [
        'zapi' => [
            'enabled' => env('COMMUNICATION_ZAPI_ENABLED', false),
            'base_url' => env('COMMUNICATION_ZAPI_BASE_URL'),
            'instance_id' => env('COMMUNICATION_ZAPI_INSTANCE_ID'),
            'token' => env('COMMUNICATION_ZAPI_TOKEN'),
            'client_token' => env('COMMUNICATION_ZAPI_CLIENT_TOKEN'),
            'webhook_secret' => env('COMMUNICATION_ZAPI_WEBHOOK_SECRET'),
            'webhook_signature_header' => env('COMMUNICATION_ZAPI_WEBHOOK_SIGNATURE_HEADER', 'X-Zapi-Signature'),
            'allow_unsigned_webhook_local' => env('COMMUNICATION_ZAPI_ALLOW_UNSIGNED_WEBHOOK_LOCAL', true),
            'fake' => env('COMMUNICATION_ZAPI_FAKE', true),
            'fake_failure' => env('COMMUNICATION_ZAPI_FAKE_FAILURE', false),
            'timeout' => env('COMMUNICATION_ZAPI_TIMEOUT', 15),
        ],
    ],

    'orchestra' => [
        'base_url' => env('ORCHESTRA_API_URL'),
        'service_token' => env('ORCHESTRA_SERVICE_TOKEN'),
    ],

    'agent' => [
        'enabled' => env('COMMUNICATION_AGENT_ENABLED', false),
        'provider' => env('COMMUNICATION_AGENT_PROVIDER', 'n8n'),
        'fake' => env('COMMUNICATION_AGENT_FAKE', true),
        'fake_failure' => env('COMMUNICATION_AGENT_FAKE_FAILURE', false),
        'n8n_webhook_url' => env('COMMUNICATION_N8N_WEBHOOK_URL'),
        'n8n_token' => env('COMMUNICATION_N8N_TOKEN'),
        'n8n_timeout' => env('COMMUNICATION_N8N_TIMEOUT', 15),
        'callback_token' => env('COMMUNICATION_AGENT_CALLBACK_TOKEN'),
    ],
];
