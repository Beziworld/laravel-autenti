<?php

declare(strict_types=1);

namespace Beziworld\Autenti;

use Beziworld\Autenti\Auth\TokenProvider;
use Beziworld\Autenti\Exceptions\InvalidConfiguration;
use Beziworld\Autenti\Support\CallbackVerifier;
use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Resolves Autenti clients for any number of API client applications or users.
 *
 * Named connections are defined in config/autenti.php in the same way as
 * database connections; options outside "connections" are shared defaults.
 * Clients for credentials stored at runtime (for example per tenant or per
 * user e-mail of the JWT bearer grant) are created with using(). Runtime
 * connections that receive callbacks are registered with
 * resolveConnectionsUsing(), so the callback route can verify them in the
 * right environment. Calls made directly on the manager go to the default
 * connection.
 *
 * @mixin AutentiClient
 */
final class AutentiManager
{
    /** @var array<string, AutentiClient> */
    private array $clients = [];

    /** @var (Closure(string): (AutentiConfig|array<string, mixed>|null))|null */
    private ?Closure $resolver = null;

    /**
     * @param (Closure(AutentiConfig): ?string)|null $callbackUrlResolver
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly Config $config,
        private readonly Cache $cache,
        private readonly ?Closure $callbackUrlResolver = null,
    ) {}

    /**
     * Client of a configured (or resolver-provided) connection; the default connection when no name is given.
     *
     * @throws InvalidConfiguration When the connection is not defined or incomplete.
     */
    public function connection(?string $name = null): AutentiClient
    {
        $name ??= $this->getDefaultConnection();

        return $this->clients[$name] ??= $this->make($this->configFor($name));
    }

    /**
     * Client for credentials provided at runtime, merged with the shared options.
     *
     * @param AutentiConfig|array<string, mixed> $config Keys as in a connection: client_id, client_secret, private_key, user_email, ...
     */
    public function using(AutentiConfig|array $config): AutentiClient
    {
        return $this->make($config instanceof AutentiConfig ? $config : AutentiConfig::fromArray(self::merge($this->shared(), $config)));
    }

    /**
     * Client of the default connection acting as another user of the same organisation (JWT bearer grant).
     */
    public function asUser(string $email, ?string $userId = null, ?string $connection = null): AutentiClient
    {
        $base = $this->configFor($connection ?? $this->getDefaultConnection());

        return $this->make(new AutentiConfig(
            clientId: $base->clientId,
            clientSecret: $base->clientSecret,
            grant: AutentiConfig::GRANT_JWT_BEARER,
            privateKey: $base->privateKey,
            privateKeyPassphrase: $base->privateKeyPassphrase,
            keyId: $base->keyId,
            algorithm: $base->algorithm,
            issuer: $base->issuer,
            userEmail: $email,
            userId: $userId,
            clientAssertion: false,
            production: $base->production,
            baseUrl: $base->baseUrl,
            language: $base->language,
            timeout: $base->timeout,
            retries: $base->retries,
            retryDelay: $base->retryDelay,
            cachePrefix: $base->cachePrefix,
            timezone: $base->timezone,
            jwksUrl: $base->jwksUrl,
            name: $base->name,
        ));
    }

    /**
     * Registers a resolver of connections that are not defined in the configuration file.
     *
     * @param Closure(string): (AutentiConfig|array<string, mixed>|null) $resolver
     */
    public function resolveConnectionsUsing(Closure $resolver): void
    {
        $this->resolver = $resolver;
        $this->clients = [];
    }

    public function getDefaultConnection(): string
    {
        $default = $this->config->get('autenti.default');

        return is_string($default) && $default !== '' ? $default : 'default';
    }

    /**
     * Names of the configured connections.
     *
     * @return list<string>
     */
    public function connectionNames(): array
    {
        $connections = $this->config->get('autenti.connections');

        if (! is_array($connections) || $connections === []) {
            return [$this->getDefaultConnection()];
        }

        return array_map(strval(...), array_keys($connections));
    }

    /**
     * Forgets resolved clients, for example after the configuration changed.
     */
    public function purge(?string $name = null): void
    {
        if ($name === null) {
            $this->clients = [];

            return;
        }

        unset($this->clients[$name]);
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $callable = [$this->connection(), $method];

        if (! is_callable($callable)) {
            throw new \BadMethodCallException(sprintf('Method %s::%s does not exist.', AutentiClient::class, $method));
        }

        return $callable(...$arguments);
    }

    private function make(AutentiConfig $config): AutentiClient
    {
        $ttl = $this->config->get('autenti.callbacks.jwks_cache_ttl', 86400);

        return new AutentiClient(
            $this->http,
            new TokenProvider($this->http, $this->cache, $config),
            new CallbackVerifier($this->http, $this->cache, is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : 86400),
            $config,
            $this->callbackUrlResolver,
        );
    }

    private function configFor(string $name): AutentiConfig
    {
        $connections = $this->config->get('autenti.connections');
        $connection = is_array($connections) ? ($connections[$name] ?? null) : null;

        if (is_array($connection)) {
            return AutentiConfig::fromArray(self::merge($this->shared(), $connection), $name);
        }

        if ((! is_array($connections) || $connections === []) && $name === $this->getDefaultConnection()) {
            // Single-client configuration with credentials at the top level.
            return AutentiConfig::fromArray($this->shared(), $name);
        }

        $resolved = $this->resolver !== null ? ($this->resolver)($name) : null;

        if ($resolved instanceof AutentiConfig) {
            return $resolved;
        }

        if (is_array($resolved)) {
            return AutentiConfig::fromArray(self::merge($this->shared(), $resolved + ['name' => $name]), $name);
        }

        throw InvalidConfiguration::unknownConnection($name);
    }

    /**
     * Applies connection options over the shared ones; empty values (unset environment variables) do not override.
     *
     * @param array<string, mixed> $shared
     * @param array<array-key, mixed> $connection
     * @return array<string, mixed>
     */
    private static function merge(array $shared, array $connection): array
    {
        foreach ($connection as $key => $value) {
            if ($value !== null && $value !== '') {
                $shared[(string) $key] = $value;
            }
        }

        return $shared;
    }

    /**
     * Options shared by every connection: everything outside "default" and "connections".
     *
     * @return array<string, mixed>
     */
    private function shared(): array
    {
        $config = $this->config->get('autenti', []);
        $config = is_array($config) ? $config : [];
        unset($config['default'], $config['connections']);

        /** @var array<string, mixed> $config */
        return $config;
    }
}
