<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Support;

use Beziworld\Autenti\Exceptions\InvalidCallback;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Verifies the x-jws-signature header of Autenti callbacks.
 *
 * The header is a JWS with a detached payload ("header..signature"). The
 * payload is the base64url encoding of the raw request body; the key is
 * selected by the "kid" of the JWS header from the JSON Web Key Set that
 * Autenti publishes per environment. Key sets are cached and fetched again
 * once when an unknown "kid" appears (key rotation).
 */
final class CallbackVerifier
{
    public const string HEADER = 'x-jws-signature';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Cache $cache,
        private readonly int $cacheTtl = 86400,
    ) {}

    /**
     * @throws InvalidCallback When the signature is missing, malformed, signed with an unknown key or invalid.
     */
    public function verify(string $rawBody, ?string $signature, string $jwksUrl): void
    {
        $signature = $signature !== null ? trim($signature) : '';

        if ($signature === '') {
            throw InvalidCallback::missingSignature();
        }

        $parts = explode('.', $signature);

        if (count($parts) !== 3) {
            throw InvalidCallback::invalidSignature('the header is not a JWS');
        }

        [$encodedHeader, $embeddedPayload, $encodedSignature] = $parts;
        $header = Jwt::decodeSegment($encodedHeader);
        $binarySignature = Base64Url::decode($encodedSignature);

        if ($header === null || $binarySignature === null || $binarySignature === '') {
            throw InvalidCallback::invalidSignature('the JWS cannot be decoded');
        }

        $algorithm = is_string($header['alg'] ?? null) ? $header['alg'] : '';
        $kid = is_string($header['kid'] ?? null) ? $header['kid'] : '';

        if (! in_array($algorithm, ['RS256', 'RS384', 'RS512'], true)) {
            throw InvalidCallback::invalidSignature('unsupported algorithm "' . $algorithm . '"');
        }

        $payload = Base64Url::encode($rawBody);

        if ($embeddedPayload !== '' && $embeddedPayload !== $payload) {
            throw InvalidCallback::invalidSignature('the signed payload differs from the body');
        }

        $pem = $this->key($jwksUrl, $kid) ?? throw InvalidCallback::unknownKey($kid);

        if (! Jwt::verify($encodedHeader . '.' . $payload, $binarySignature, $pem, $algorithm)) {
            throw InvalidCallback::invalidSignature('the signature does not match the body');
        }
    }

    /**
     * Forgets the cached key set of the URL.
     */
    public function forget(string $jwksUrl): void
    {
        $this->cache->forget($this->cacheKey($jwksUrl));
    }

    private function key(string $jwksUrl, string $kid): ?string
    {
        $keys = $this->cache->get($this->cacheKey($jwksUrl));
        $keys = is_array($keys) ? $keys : null;

        if ($keys === null || ! isset($keys[$kid])) {
            $keys = $this->fetch($jwksUrl);

            if ($keys !== []) {
                $this->cache->put($this->cacheKey($jwksUrl), $keys, $this->cacheTtl);
            }
        }

        $pem = $keys[$kid] ?? null;

        return is_string($pem) ? $pem : null;
    }

    /**
     * @return array<string, string> kid => PEM
     */
    private function fetch(string $jwksUrl): array
    {
        try {
            $response = $this->http->createPendingRequest()->acceptJson()->timeout(10)->get($jwksUrl);
        } catch (Throwable) {
            return [];
        }

        $set = $response->successful() ? $response->json() : null;
        $keys = [];

        foreach (is_array($set) && is_array($set['keys'] ?? null) ? $set['keys'] : [] as $jwk) {
            if (! is_array($jwk) || ! is_string($jwk['kid'] ?? null) || (isset($jwk['use']) && $jwk['use'] !== 'sig')) {
                continue;
            }

            $pem = Jwk::toPem($jwk);

            if ($pem !== null) {
                $keys[$jwk['kid']] = $pem;
            }
        }

        return $keys;
    }

    private function cacheKey(string $jwksUrl): string
    {
        return 'autenti:jwks:' . sha1($jwksUrl);
    }
}
