<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Every connection is one Autenti API client application. The API is
    | available for PRO, PREMIUM and ENTERPRISE plans; the client id and
    | secret are created in the Autenti management UI of the environment the
    | connection uses.
    |
    | "grant" selects the OAuth2 flow:
    | - "jwt_bearer" (default): the package signs a JWT assertion for
    |   "user_email" with "private_key" (PEM string or file path; its public
    |   key must be registered for the API client). The user must belong to
    |   the organisation of the API client.
    | - "client_credentials": an automated agent without a user context
    |   (restricted scopes); with "client_assertion" set to true the client
    |   authenticates with a JWT signed by "private_key" instead of the secret.
    |
    */

    'default' => env('AUTENTI_CONNECTION', 'default'),

    'connections' => [

        'default' => [
            'client_id' => env('AUTENTI_CLIENT_ID'),
            'client_secret' => env('AUTENTI_CLIENT_SECRET'),
            'grant' => env('AUTENTI_GRANT', 'jwt_bearer'),
            'private_key' => env('AUTENTI_PRIVATE_KEY'),
            'private_key_passphrase' => env('AUTENTI_PRIVATE_KEY_PASSPHRASE'),
            'key_id' => env('AUTENTI_KEY_ID'),
            'algorithm' => env('AUTENTI_JWT_ALGORITHM', 'RS256'),
            'issuer' => env('AUTENTI_JWT_ISSUER'),
            'user_email' => env('AUTENTI_USER_EMAIL'),
            'user_id' => env('AUTENTI_USER_ID'),
            'client_assertion' => (bool) env('AUTENTI_CLIENT_ASSERTION', false),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | "acceptance" uses https://api.accept.autenti.net/api/v2, "production"
    | https://api.autenti.com/api/v2. The environments are separate Autenti
    | instances with separate accounts and API clients. "base_url" overrides
    | the host.
    |
    */

    'environment' => env('AUTENTI_ENVIRONMENT', 'acceptance'),

    'base_url' => env('AUTENTI_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Defaults and transport
    |--------------------------------------------------------------------------
    |
    | "language" is the process language of new documents. Reads are
    | retried "retries" times on connection errors and HTTP 5xx; writes
    | (creation, files, actions, callbacks) are never retried. Access tokens
    | are cached in the default cache store under "cache_prefix" until
    | shortly before expiry.
    |
    */

    'language' => env('AUTENTI_LANGUAGE', 'pl'),

    'timeout' => (int) env('AUTENTI_TIMEOUT', 30),

    'retries' => (int) env('AUTENTI_RETRIES', 2),

    'retry_delay' => (int) env('AUTENTI_RETRY_DELAY', 500),

    'cache_prefix' => env('AUTENTI_CACHE_PREFIX', 'autenti'),

    'timezone' => env('AUTENTI_TIMEZONE', 'Europe/Warsaw'),

    /*
    |--------------------------------------------------------------------------
    | Callbacks
    |--------------------------------------------------------------------------
    |
    | Route receiving Autenti callbacks: POST {path}/{connection?}. The
    | x-jws-signature header is verified with the public keys published by
    | Autenti for the environment of the connection (cached for
    | "jwks_cache_ttl" seconds); forged callbacks get HTTP 403. Register the
    | URL with `php artisan autenti:callbacks --register`.
    |
    */

    'callbacks' => [
        'enabled' => (bool) env('AUTENTI_CALLBACKS_ENABLED', true),
        'path' => env('AUTENTI_CALLBACK_PATH', 'autenti/callback'),
        'name' => 'autenti.callback',
        'middleware' => ['api'],
        'verify_signature' => (bool) env('AUTENTI_VERIFY_SIGNATURE', true),
        'jwks_url' => env('AUTENTI_JWKS_URL'),
        'jwks_cache_ttl' => (int) env('AUTENTI_JWKS_CACHE_TTL', 86400),
    ],

];
