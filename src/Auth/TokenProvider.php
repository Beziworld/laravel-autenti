<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Auth;

use Beziworld\Autenti\AutentiConfig;
use Beziworld\Autenti\Data\Challenge;
use Beziworld\Autenti\Exceptions\ApiException;
use Beziworld\Autenti\Exceptions\AuthenticationFailed;
use Beziworld\Autenti\Support\Jwt;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Obtains and caches OAuth2 access tokens of one Autenti API client.
 *
 * The JWT bearer grant (RFC 7523 section 2.1) authenticates the configured
 * user with an assertion signed by the private key of the integration: aud
 * "autenti.com", email and email_verified, iat within 60 seconds of the
 * server time and a short expiry. The client credentials grant
 * authenticates the client itself, with the secret or a client assertion
 * (RFC 7523 section 2.2, sub = client_id). Tokens are cached until one
 * minute before expires_in.
 */
final class TokenProvider
{
    private const int ASSERTION_LIFETIME = 300;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Cache $cache,
        private readonly AutentiConfig $config,
    ) {}

    /**
     * @throws AuthenticationFailed When Autenti rejects the credentials.
     * @throws ApiException When Autenti cannot be reached.
     */
    public function token(): string
    {
        $cached = $this->cache->get($this->cacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        [$token, $expiresIn] = $this->request();
        $this->cache->put($this->cacheKey(), $token, max(30, $expiresIn - 60));

        return $token;
    }

    public function forget(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    /**
     * Body of the token request for the configured grant.
     *
     * @return array<string, string>
     */
    public function tokenRequest(?int $now = null): array
    {
        $now ??= time();

        if ($this->config->grant === AutentiConfig::GRANT_JWT_BEARER) {
            return [
                'client_id' => $this->config->clientId,
                'client_secret' => (string) $this->config->clientSecret,
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->sign(array_filter([
                    'iss' => $this->config->issuer ?? $this->config->clientId,
                    'sub' => $this->config->userId ?? $this->config->userEmail,
                    'aud' => 'autenti.com',
                    'iat' => $now,
                    'exp' => $now + self::ASSERTION_LIFETIME,
                    'jti' => bin2hex(random_bytes(16)),
                    'email' => $this->config->userEmail,
                    'email_verified' => true,
                ], static fn(mixed $value): bool => $value !== null)),
            ];
        }

        if ($this->config->clientAssertion) {
            return [
                'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                'client_assertion' => $this->sign([
                    'iss' => $this->config->issuer ?? $this->config->clientId,
                    'sub' => $this->config->clientId,
                    'aud' => 'autenti.com',
                    'jti' => bin2hex(random_bytes(16)),
                    'iat' => $now,
                    'exp' => $now + self::ASSERTION_LIFETIME,
                ]),
                'grant_type' => 'client_credentials',
            ];
        }

        return [
            'client_id' => $this->config->clientId,
            'client_secret' => (string) $this->config->clientSecret,
            'grant_type' => 'client_credentials',
        ];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function request(): array
    {
        try {
            $response = $this->http
                ->createPendingRequest()
                ->acceptJson()
                ->asJson()
                ->timeout($this->config->timeout)
                ->withUserAgent('beziworld-laravel-autenti/1.0')
                ->post($this->config->apiUrl() . '/auth/token', $this->tokenRequest());
        } catch (ConnectionException $exception) {
            throw ApiException::transport($exception);
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        if ($response->failed()) {
            $error = is_string($payload['error'] ?? null) ? $payload['error'] : null;
            $description = is_string($payload['error_description'] ?? null) ? $payload['error_description'] : (is_string($payload['detail'] ?? null) ? $payload['detail'] : null);

            throw AuthenticationFailed::rejected($error, $response->status(), $description, Challenge::fromHeader($response->header('X-Challenge')));
        }

        $token = $payload['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw AuthenticationFailed::unexpected('access_token is missing');
        }

        $expiresIn = $payload['expires_in'] ?? 3600;

        return [$token, is_numeric($expiresIn) ? (int) $expiresIn : 3600];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function sign(array $claims): string
    {
        return Jwt::sign($claims, (string) $this->config->privateKey, $this->config->algorithm, $this->config->keyId, $this->config->privateKeyPassphrase);
    }

    private function cacheKey(): string
    {
        return sprintf(
            '%s:token:%s',
            $this->config->cachePrefix,
            sha1($this->config->apiUrl() . '|' . $this->config->clientId . '|' . $this->config->grant . '|' . ($this->config->userEmail ?? '')),
        );
    }
}
