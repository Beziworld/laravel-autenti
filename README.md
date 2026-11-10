# Laravel Autenti

[![tests](https://github.com/beziworld/laravel-autenti/actions/workflows/tests.yml/badge.svg)](https://github.com/beziworld/laravel-autenti/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/beziworld/laravel-autenti.svg)](https://packagist.org/packages/beziworld/laravel-autenti)
[![License](https://img.shields.io/packagist/l/beziworld/laravel-autenti.svg)](LICENSE.md)

[Autenti](https://autenti.com) electronic signatures for Laravel, built on the public
[Autenti Document Process API v2](https://developers.autenti.com):

- OAuth2 JWT bearer grant with an RS256/RS512 assertion signed by your key pair, client credentials (secret or
  client assertion), tokens cached per client and user, acting as another user of the organisation
- document processes ("envelopes"): list with filters, details, drafts with participants, signing order, signature
  type (basic, advanced, qualified), SMS verification, return URL and expiration
- files: upload (multipart `fileMeta` + `file`), list, remove, download any version, download the signed result
- actions: send, withdraw, remind (with automatic participant selection), discovery of available actions, generic
  challenge-assertion actions, action history
- participant action links (signing links) with a redirect URL
- callbacks: registration, listing, deletion, `autenti:callbacks` command and a route that verifies the
  `x-jws-signature` (detached JWS, RS256) with the public keys Autenti publishes, then dispatches Laravel events
- acceptance and production environments, many API clients through named and runtime connections

[Polska wersja dokumentacji](README.pl.md)

## Why this package

At the time of writing there was no PHP or Laravel client for Autenti on Packagist or GitHub (the similarly named
`autentique` packages target an unrelated Brazilian service). Integrating the API by hand means implementing the
RFC 7523 JWT assertion, the challenge-assertion action model with base64 headers, multipart uploads with typed parts
and detached JWS verification against a JWKS. This package does all of that with the Laravel HTTP client
(`Http::fake` works) and typed data objects.

The API is available for Autenti PRO, PREMIUM and ENTERPRISE plans.

## Installation

```bash
composer require beziworld/laravel-autenti
php artisan vendor:publish --tag=autenti-config   # optional
```

```dotenv
AUTENTI_ENVIRONMENT=acceptance        # api.accept.autenti.net; production = api.autenti.com
AUTENTI_CLIENT_ID=...                 # API client created in the Autenti management UI
AUTENTI_CLIENT_SECRET=...
AUTENTI_GRANT=jwt_bearer              # or client_credentials
AUTENTI_PRIVATE_KEY=/path/to/private.pem   # PEM file or PEM string; its public key is registered for the client
AUTENTI_KEY_ID=key-1                  # optional "kid" of the registered public key
AUTENTI_JWT_ISSUER=example.com        # identification of your organisation (defaults to the client id)
AUTENTI_USER_EMAIL=robot@example.com  # Autenti user the integration acts as
```

The JWT bearer grant authenticates the user given by `AUTENTI_USER_EMAIL`, who must belong to the organisation of the
API client. The assertion contains `aud: autenti.com`, `email`, `email_verified: true`, `iat` and a five minute
`exp`; Autenti rejects assertions whose `iat` is more than 60 seconds off, so keep the server clock synchronised.
Generate the key pair with `openssl genrsa -out private.pem 2048` and `openssl rsa -in private.pem -pubout` and
register the public key with Autenti.

## Sending a document

```php
use Beziworld\Autenti\Data\Constraint;
use Beziworld\Autenti\Data\DocumentRequest;
use Beziworld\Autenti\Data\ParticipantRequest;
use Beziworld\Autenti\Enums\SignatureType;
use Beziworld\Autenti\Facades\Autenti;

$document = Autenti::sendDocument(new DocumentRequest(
    title: 'Lease agreement 12/2026',
    participants: [
        ParticipantRequest::signer('john.doe@example.com', 'John', 'Doe',
            signatureType: SignatureType::Qualified,
            priority: 1,                                  // signing order
            phoneVerification: '+48 601 123 456',         // SMS code before signing
            returnUrl: 'https://app.example.com/signed',
            organizationName: 'Example sp. z o.o.', organizationTaxId: '7831693251', position: 'CEO'),
        ParticipantRequest::signer('mary@example.com', 'Mary', 'Smith', priority: 2),
        ParticipantRequest::viewer('office@example.com', 'Office', 'Team'),
    ],
    language: 'pl',
    constraints: [Constraint::expiration(now()->addDays(14))],
), files: [
    storage_path('app/contracts/lease.pdf') => null,      // path => null, or 'name.pdf' => $contents
]);

$document->id;       // "DOCUMENT_PROCESS:3429836f-..."
$document->status;   // DocumentStatus::Processing
```

Step by step: `createDocument()` creates a draft, `addFile()` / `addFileFromPath()` upload source files (20 MB in
total), `send()` sends it. Drafts can be changed with `updateDocument()`, `addParticipant()`, `removeParticipant()`
and `removeFile()`. Writes are never retried automatically.

## Status, files and links

```php
use Beziworld\Autenti\Data\DocumentFilter;
use Beziworld\Autenti\Enums\ListStatus;

$document = Autenti::document('3429836f-ba99-4b4c-a689-37f5e9f52106');   // the prefix is added when missing
$document->isCompleted();
foreach ($document->signers() as $signer) {
    $signer->email; $signer->status; $signer->hasCompleted();
}

Autenti::documents(new DocumentFilter(status: ListStatus::Completed, limit: 128, descending: true));

$signed = Autenti::downloadSignedFile($document->id);     // null until the document is completed
$signed?->saveTo(storage_path('app/signed/' . $signed->filename()));

Autenti::signingLink($document->id, $signer->partyId, redirectUrl: 'https://app.example.com/back');
Autenti::remind($document->id);       // every participant still available for a reminder
Autenti::withdraw($document->id);
Autenti::history($document->id);      // list of Event
Autenti::availableActions($document->id);
```

## Callbacks and events

Register the package route once (the organisation may have up to 5 callbacks; the super administrator scope
`notification_management` is required):

```bash
php artisan autenti:callbacks --register                 # https://your-app/autenti/callback/default
php artisan autenti:callbacks --delete=CALLBACK_ADAPTER:API_V2:...
```

Autenti posts a JSON list of events with an `x-jws-signature` header. The route verifies the detached JWS with the
key set of the environment (`autenti.com/developers/keys/webhook.jwks` or `accept-webhook.jwks`, cached for a day and
fetched again on an unknown key) and dispatches:

| Event | Autenti event type |
|---|---|
| `AutentiEventReceived` | every event |
| `DocumentCompleted` | DOCUMENT_PROCESS_COMPLETED |
| `DocumentRejected` | DOCUMENT_PROCESS_REJECTED |
| `DocumentWithdrawn` | DOCUMENT_PROCESS_WITHDRAWN |
| `DocumentExpired` | DOCUMENT_PROCESS_EXPIRED |
| `SigningCompleted` | SIGNING_PROCESS_COMPLETED |
| `ApprovalConsented` | APPROVAL_PROCESS_CONSENTED |
| `ReviewCompleted` | REVIEW_PROCESS_COMPLETED |
| `SigningHandedOver` | DOCUMENT_SIGNING_HANDED_OVER |

```php
use Beziworld\Autenti\Events\DocumentCompleted;
use Beziworld\Autenti\Facades\Autenti;

Event::listen(function (DocumentCompleted $event): void {
    $file = Autenti::connection($event->connection)->downloadSignedFile($event->documentId());
    // store $file->contents
});
```

Callbacks are tenant wide (documents sent from the Autenti UI arrive too) and are repeated until HTTP 2xx
(immediately, after 30 minutes, 6 hours and 24 hours), so listeners must be idempotent. Forged callbacks get
HTTP 403, malformed ones HTTP 400. For a custom route use `Autenti::parseCallback($rawBody, $signatureHeader)`.

## Many clients and users

```php
Autenti::connection('second-company')->documents();
Autenti::asUser('anna@example.com')->createDocument($request);   // JWT bearer as another user of the organisation
Autenti::using(['client_id' => ..., 'client_secret' => ..., 'grant' => 'client_credentials', 'name' => 'tenant-7']);
Autenti::resolveConnectionsUsing(fn (string $name): ?array => Tenant::firstWhere('slug', $name)?->autentiConfig());
```

## Errors

| Exception | When |
|---|---|
| `InvalidConfiguration` | missing client id, secret, user e-mail or private key; unknown grant, environment or connection; unusable key |
| `AuthenticationFailed` | token request rejected (`error()` such as `invalid_client`, `invalid_grant`; `requiresMultiFactor()`) |
| `ApiException` | HTTP error with the Autenti `type()` (e.g. `/error/resource-not-found`), `instance()`, `retryAfter()`, or a connection failure |
| `ChallengeRequired` | HTTP 403 with an X-Challenge the call did not answer; `challenge()` describes it |
| `InvalidRequest` | data violates a documented constraint (e-mail, NIP, title, limit 128, offset 256, HTTPS callback) |
| `InvalidCallback` | missing or invalid `x-jws-signature`, unknown key, malformed body |

All extend `AutentiException`. Reads are retried on connection errors and HTTP 5xx; HTTP 429 is never retried
automatically, as the API terms require.

## Development

```bash
composer install
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pest
```

Tests generate throwaway RSA keys at runtime (phpseclib) and verify the callback example of the official
documentation with the published production key.

## Author

Created and maintained by **Kamil Ćwiertnia**, [beziworld.eu](https://beziworld.eu).

- E-mail: [biuro@beziworld.eu](mailto:biuro@beziworld.eu)
- Issues: [GitHub issues](https://github.com/beziworld/laravel-autenti/issues)
- Security vulnerabilities: see [SECURITY.md](SECURITY.md)

Need an integration? Get in touch through [beziworld.eu](https://beziworld.eu).

### Other packages by the author

[laravel-edoreczenia](https://github.com/beziworld/laravel-edoreczenia) ·
[laravel-ksef](https://github.com/beziworld/laravel-ksef) ·
[laravel-fakturownia](https://github.com/beziworld/laravel-fakturownia) ·
[laravel-comfino](https://github.com/beziworld/laravel-comfino)

## License

MIT. See [LICENSE.md](LICENSE.md). This package is not affiliated with Autenti sp. z o.o.
