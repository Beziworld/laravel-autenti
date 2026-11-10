<?php

declare(strict_types=1);

use Beziworld\Autenti\AutentiClient;
use Beziworld\Autenti\AutentiConfig;
use Beziworld\Autenti\AutentiManager;
use Beziworld\Autenti\Exceptions\InvalidConfiguration;
use Beziworld\Autenti\Facades\Autenti;
use Beziworld\Autenti\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('resolves connections from the container', function (): void {
    expect(app(AutentiClient::class)->connectionName())->toBe('default')
        ->and(app(AutentiConfig::class)->clientId)->toBe(TestCase::CLIENT_ID)
        ->and(Autenti::connection())->toBe(Autenti::connection('default'))
        ->and(Autenti::connectionNames())->toBe(['default', 'robot'])
        ->and(Autenti::config()->apiUrl())->toBe(AutentiConfig::ACCEPTANCE_URL)
        ->and(Autenti::config()->jwksUrl())->toBe(AutentiConfig::ACCEPTANCE_JWKS);
});

it('builds the callback URL of each connection', function (): void {
    expect(Autenti::callbackUrl())->toBe('https://app.example.com/autenti/callback/default')
        ->and(Autenti::connection('robot')->callbackUrl())->toBe('https://app.example.com/autenti/callback/robot')
        ->and(route('autenti.callback'))->toBe('https://app.example.com/autenti/callback');
});

it('acts as another user of the organisation', function (): void {
    Http::fake(['*/auth/token' => Http::response(autentiToken())]);

    Autenti::asUser('anna@example.com', 'user-42')->token();

    Http::assertSent(function (Request $request): bool {
        $claims = json_decode((string) Beziworld\Autenti\Support\Base64Url::decode(explode('.', (string) $request['assertion'])[1]), true);

        return $claims['email'] === 'anna@example.com' && $claims['sub'] === 'user-42';
    });
});

it('caches tokens per user', function (): void {
    Http::fakeSequence('*/auth/token')->push(autentiToken('first'))->push(autentiToken('second'));

    expect(Autenti::token())->toBe('first')
        ->and(Autenti::asUser('anna@example.com')->token())->toBe('second')
        ->and(Autenti::token())->toBe('first');
});

it('fails clearly for unknown connections', function (): void {
    Autenti::connection('missing');
})->throws(InvalidConfiguration::class, 'connection "missing" is not defined');

it('supports a single client configured at the top level', function (): void {
    config()->set('autenti.connections', []);
    config()->set('autenti.client_id', 'top');
    config()->set('autenti.client_secret', 'secret');
    config()->set('autenti.grant', 'client_credentials');
    app()->forgetInstance(AutentiManager::class);

    expect(Autenti::connection()->config()->clientId)->toBe('top');
});

it('normalises configuration values', function (): void {
    $config = AutentiConfig::fromArray([
        'client_id' => 'x',
        'client_secret' => 'y',
        'grant' => 'CLIENT_CREDENTIALS',
        'base_url' => 'https://proxy.example.com/api/v2/',
        'language' => 'EN',
        'timeout' => 'abc',
        'callbacks' => ['jwks_url' => 'https://keys.example.com/jwks'],
    ]);

    expect($config->apiUrl())->toBe('https://proxy.example.com/api/v2')
        ->and($config->grant)->toBe('client_credentials')
        ->and($config->language)->toBe('en')
        ->and($config->timeout)->toBe(30)
        ->and($config->jwksUrl())->toBe('https://keys.example.com/jwks')
        ->and($config->usesPrivateKey())->toBeFalse();
});

it('forwards unknown methods and rejects missing ones', function (): void {
    expect(fn() => app(AutentiManager::class)->doesNotExist())->toThrow(BadMethodCallException::class);
});

it('lists and registers callbacks with the artisan command', function (): void {
    Http::fake([
        '*/auth/token' => Http::response(autentiToken()),
        '*/applications/callbacks' => Http::sequence()
            ->push(['id' => 'CALLBACK_ADAPTER:API_V2:1', 'callbackAdapterId' => 'CALLBACK_ADAPTER:API_V2', 'callbackParameters' => ['callbackUrl' => 'https://hooks.example.com/a']], 201)
            ->push([['id' => 'CALLBACK_ADAPTER:API_V2:1', 'callbackAdapterId' => 'CALLBACK_ADAPTER:API_V2', 'callbackParameters' => ['callbackUrl' => 'https://hooks.example.com/a']]]),
    ]);

    $this->artisan('autenti:callbacks', ['--url' => 'https://hooks.example.com/a'])
        ->expectsOutputToContain('Registered callback CALLBACK_ADAPTER:API_V2:1')
        ->expectsOutputToContain('https://hooks.example.com/a')
        ->assertSuccessful();
});

it('reports command failures', function (): void {
    Http::fake(['*/auth/token' => Http::response(['error' => 'invalid_client'], 401)]);

    $this->artisan('autenti:callbacks', ['--connection' => 'robot'])
        ->expectsOutputToContain('invalid_client')
        ->assertFailed();
});

it('publishes the configuration file', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'autenti-config', '--force' => true])->assertSuccessful();

    expect(file_exists(config_path('autenti.php')))->toBeTrue();

    @unlink(config_path('autenti.php'));
});
