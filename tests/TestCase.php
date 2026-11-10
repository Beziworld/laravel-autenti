<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Tests;

use Beziworld\Autenti\AutentiServiceProvider;
use Beziworld\Autenti\Support\Base64Url;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use phpseclib3\Crypt\RSA;

abstract class TestCase extends Orchestra
{
    public const string CLIENT_ID = '39d00d32-8c4e-4bf9-a7d2-bec56dcee0b5';

    public const string CLIENT_SECRET = 'test-client-secret';

    public const string USER_EMAIL = 'robot@example.com';

    public const string API = 'https://api.accept.autenti.net/api/v2';

    public const string TOKEN = 'eyJ.test-access-token';

    private static ?string $clientKey = null;

    private static ?string $callbackKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * Throwaway RSA private key (PKCS#1 padding) of the integration, generated once per test run.
     */
    public static function clientPrivateKey(): string
    {
        return self::$clientKey ??= (string) RSA::createKey(2048)->withPadding(RSA::ENCRYPTION_PKCS1 | RSA::SIGNATURE_PKCS1)->toString('PKCS8');
    }

    /**
     * Throwaway RSA private key that plays the role of the Autenti callback signing key.
     */
    public static function callbackPrivateKey(): string
    {
        return self::$callbackKey ??= (string) RSA::createKey(2048)->withPadding(RSA::ENCRYPTION_PKCS1 | RSA::SIGNATURE_PKCS1)->toString('PKCS8');
    }

    /**
     * Public JWK of a private key, in the layout of the Autenti JWKS documents.
     *
     * @return array<string, string>
     */
    public static function jwk(string $privateKey, string $kid): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($privateKey));

        return [
            'kty' => 'RSA',
            'n' => Base64Url::encode($details['rsa']['n']),
            'e' => Base64Url::encode($details['rsa']['e']),
            'alg' => 'RS256',
            'kid' => $kid,
            'use' => 'sig',
        ];
    }

    protected function getPackageProviders($app): array
    {
        return [AutentiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'https://app.example.com');
        $app['url']->forceRootUrl('https://app.example.com');
        $app['url']->forceScheme('https');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('autenti.connections.default', [
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'grant' => 'jwt_bearer',
            'private_key' => self::clientPrivateKey(),
            'key_id' => 'integration-key-1',
            'issuer' => 'example.com',
            'user_email' => self::USER_EMAIL,
        ]);
        $app['config']->set('autenti.connections.robot', [
            'client_id' => 'robot-client',
            'client_secret' => 'robot-secret',
            'grant' => 'client_credentials',
        ]);
        $app['config']->set('autenti.environment', 'acceptance');
        $app['config']->set('autenti.retry_delay', 0);
    }
}
