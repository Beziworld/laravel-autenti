<?php

declare(strict_types=1);

namespace Beziworld\Autenti;

use Beziworld\Autenti\Exceptions\InvalidConfiguration;

/**
 * Validated, immutable configuration of one Autenti API client application.
 */
final readonly class AutentiConfig
{
    public const string PRODUCTION_URL = 'https://api.autenti.com/api/v2';

    public const string ACCEPTANCE_URL = 'https://api.accept.autenti.net/api/v2';

    public const string PRODUCTION_JWKS = 'https://autenti.com/developers/keys/webhook.jwks';

    public const string ACCEPTANCE_JWKS = 'https://autenti.com/developers/keys/accept-webhook.jwks';

    public const string GRANT_JWT_BEARER = 'jwt_bearer';

    public const string GRANT_CLIENT_CREDENTIALS = 'client_credentials';

    /**
     * @param string|null $privateKey PEM encoded private key or a path to a PEM file.
     * @param string|null $name Connection name; it is embedded in the default callback URL.
     */
    public function __construct(
        public string $clientId,
        #[\SensitiveParameter]
        public ?string $clientSecret = null,
        public string $grant = self::GRANT_JWT_BEARER,
        #[\SensitiveParameter]
        public ?string $privateKey = null,
        #[\SensitiveParameter]
        public ?string $privateKeyPassphrase = null,
        public ?string $keyId = null,
        public string $algorithm = 'RS256',
        public ?string $issuer = null,
        public ?string $userEmail = null,
        public ?string $userId = null,
        public bool $clientAssertion = false,
        public bool $production = false,
        public ?string $baseUrl = null,
        public string $language = 'pl',
        public int $timeout = 30,
        public int $retries = 2,
        public int $retryDelay = 500,
        public string $cachePrefix = 'autenti',
        public string $timezone = 'Europe/Warsaw',
        public ?string $jwksUrl = null,
        public ?string $name = null,
    ) {}

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidConfiguration When a value required by the selected grant is missing.
     */
    public static function fromArray(array $config, ?string $name = null): self
    {
        $grant = strtolower(self::string($config['grant'] ?? null) ?? self::GRANT_JWT_BEARER);
        $environment = strtolower(self::string($config['environment'] ?? null) ?? 'acceptance');
        $algorithm = strtoupper(self::string($config['algorithm'] ?? null) ?? 'RS256');
        $callbacks = is_array($config['callbacks'] ?? null) ? $config['callbacks'] : [];

        if (! in_array($grant, [self::GRANT_JWT_BEARER, self::GRANT_CLIENT_CREDENTIALS], true)) {
            throw InvalidConfiguration::invalid('grant', 'jwt_bearer or client_credentials');
        }

        if (! in_array($environment, ['production', 'acceptance'], true)) {
            throw InvalidConfiguration::invalid('environment', 'production or acceptance');
        }

        if (! in_array($algorithm, ['RS256', 'RS384', 'RS512'], true)) {
            throw InvalidConfiguration::invalid('algorithm', 'RS256, RS384 or RS512');
        }

        $config = new self(
            clientId: self::string($config['client_id'] ?? null) ?? throw InvalidConfiguration::missing('client_id', 'AUTENTI_CLIENT_ID'),
            clientSecret: self::string($config['client_secret'] ?? null),
            grant: $grant,
            privateKey: self::string($config['private_key'] ?? null),
            privateKeyPassphrase: self::string($config['private_key_passphrase'] ?? null),
            keyId: self::string($config['key_id'] ?? null),
            algorithm: $algorithm,
            issuer: self::string($config['issuer'] ?? null),
            userEmail: self::string($config['user_email'] ?? null),
            userId: self::string($config['user_id'] ?? null),
            clientAssertion: filter_var($config['client_assertion'] ?? false, FILTER_VALIDATE_BOOL),
            production: $environment === 'production',
            baseUrl: self::string($config['base_url'] ?? null),
            language: strtolower(self::string($config['language'] ?? null) ?? 'pl'),
            timeout: self::positive($config['timeout'] ?? null, 30),
            retries: self::natural($config['retries'] ?? null, 2),
            retryDelay: self::natural($config['retry_delay'] ?? null, 500),
            cachePrefix: self::string($config['cache_prefix'] ?? null) ?? 'autenti',
            timezone: self::string($config['timezone'] ?? null) ?? 'Europe/Warsaw',
            jwksUrl: self::string($config['jwks_url'] ?? $callbacks['jwks_url'] ?? null),
            name: self::string($config['name'] ?? null) ?? $name,
        );

        $config->validate();

        return $config;
    }

    /**
     * Base URL of the API without a trailing slash, e.g. "https://api.autenti.com/api/v2".
     */
    public function apiUrl(): string
    {
        if ($this->baseUrl !== null) {
            return rtrim($this->baseUrl, '/');
        }

        return $this->production ? self::PRODUCTION_URL : self::ACCEPTANCE_URL;
    }

    /**
     * URL of the JSON Web Key Set that signs callbacks in the environment of the connection.
     */
    public function jwksUrl(): string
    {
        return $this->jwksUrl ?? ($this->production ? self::PRODUCTION_JWKS : self::ACCEPTANCE_JWKS);
    }

    public function usesPrivateKey(): bool
    {
        return $this->grant === self::GRANT_JWT_BEARER || $this->clientAssertion;
    }

    /**
     * @throws InvalidConfiguration
     */
    private function validate(): void
    {
        if ($this->usesPrivateKey() && $this->privateKey === null) {
            throw InvalidConfiguration::missing('private_key', 'AUTENTI_PRIVATE_KEY');
        }

        if ($this->grant === self::GRANT_JWT_BEARER) {
            $this->clientSecret ?? throw InvalidConfiguration::missing('client_secret', 'AUTENTI_CLIENT_SECRET');
            $this->userEmail ?? throw InvalidConfiguration::missing('user_email', 'AUTENTI_USER_EMAIL');
        }

        if ($this->grant === self::GRANT_CLIENT_CREDENTIALS && ! $this->clientAssertion && $this->clientSecret === null) {
            throw InvalidConfiguration::missing('client_secret', 'AUTENTI_CLIENT_SECRET');
        }
    }

    private static function string(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function positive(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    private static function natural(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : $default;
    }
}
