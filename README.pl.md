# Laravel Autenti

[![tests](https://github.com/beziworld/laravel-autenti/actions/workflows/tests.yml/badge.svg)](https://github.com/beziworld/laravel-autenti/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/beziworld/laravel-autenti.svg)](https://packagist.org/packages/beziworld/laravel-autenti)
[![License](https://img.shields.io/packagist/l/beziworld/laravel-autenti.svg)](LICENSE.md)

Podpisy elektroniczne [Autenti](https://autenti.com) w Laravelu, na bazie publicznego
[Autenti Document Process API v2](https://developers.autenti.com):

- OAuth2: JWT bearer z asercją RS256/RS512 podpisaną Twoją parą kluczy, client credentials (sekret albo asercja
  klienta), tokeny w cache per klient i użytkownik, działanie w imieniu innego użytkownika organizacji
- procesy dokumentów („koperty”): lista z filtrami, szczegóły, szkice z uczestnikami, kolejność podpisów, typ podpisu
  (zwykły, zaawansowany, kwalifikowany), weryfikacja SMS, adres powrotu i termin ważności
- pliki: wysyłka (multipart `fileMeta` + `file`), lista, usuwanie, pobranie dowolnej wersji i podpisanego wyniku
- akcje: wysłanie, wycofanie, przypomnienie (z automatycznym wyborem uczestników), odkrywanie dostępnych akcji,
  ogólny model challenge-assertion, historia akcji
- linki akcji uczestników (linki do podpisu) z adresem przekierowania
- callbacki: rejestracja, lista, usuwanie, komenda `autenti:callbacks` i trasa weryfikująca `x-jws-signature`
  (odłączony JWS, RS256) kluczami publikowanymi przez Autenti, a potem wysyłająca zdarzenia Laravela
- środowiska akceptacyjne i produkcyjne, wielu klientów API przez nazwane połączenia i połączenia w locie

[English documentation](README.md)

## Dlaczego ten pakiet

W chwili pisania nie było klienta Autenti dla PHP ani Laravela na Packagist czy GitHubie (pakiety o podobnej nazwie
`autentique` dotyczą niezwiązanej brazylijskiej usługi). Ręczna integracja wymaga zaimplementowania asercji JWT z
RFC 7523, modelu akcji challenge-assertion z nagłówkami base64, wysyłki multipart z typowanymi częściami i weryfikacji
odłączonego JWS względem JWKS. Pakiet robi to wszystko klientem HTTP Laravela (`Http::fake` działa) i typowanymi
obiektami danych.

API jest dostępne w planach Autenti PRO, PREMIUM i ENTERPRISE.

## Instalacja

```bash
composer require beziworld/laravel-autenti
php artisan vendor:publish --tag=autenti-config   # opcjonalnie
```

```dotenv
AUTENTI_ENVIRONMENT=acceptance        # api.accept.autenti.net; produkcja = api.autenti.com
AUTENTI_CLIENT_ID=...                 # klient API utworzony w panelu zarządzania Autenti
AUTENTI_CLIENT_SECRET=...
AUTENTI_GRANT=jwt_bearer              # albo client_credentials
AUTENTI_PRIVATE_KEY=/sciezka/private.pem   # plik PEM albo tekst PEM; klucz publiczny jest zarejestrowany dla klienta
AUTENTI_KEY_ID=key-1                  # opcjonalny „kid” zarejestrowanego klucza publicznego
AUTENTI_JWT_ISSUER=example.com        # identyfikator Twojej organizacji (domyślnie client id)
AUTENTI_USER_EMAIL=robot@example.com  # użytkownik Autenti, w imieniu którego działa integracja
```

Grant JWT bearer uwierzytelnia użytkownika z `AUTENTI_USER_EMAIL`, który musi należeć do organizacji klienta API.
Asercja zawiera `aud: autenti.com`, `email`, `email_verified: true`, `iat` i pięciominutowe `exp`; Autenti odrzuca
asercje, których `iat` różni się o więcej niż 60 sekund, więc zegar serwera musi być zsynchronizowany. Parę kluczy
wygenerujesz poleceniami `openssl genrsa -out private.pem 2048` i `openssl rsa -in private.pem -pubout`, a klucz
publiczny rejestrujesz w Autenti.

## Wysłanie dokumentu

```php
use Beziworld\Autenti\Data\Constraint;
use Beziworld\Autenti\Data\DocumentRequest;
use Beziworld\Autenti\Data\ParticipantRequest;
use Beziworld\Autenti\Enums\SignatureType;
use Beziworld\Autenti\Facades\Autenti;

$document = Autenti::sendDocument(new DocumentRequest(
    title: 'Umowa najmu 12/2026',
    participants: [
        ParticipantRequest::signer('jan.kowalski@example.com', 'Jan', 'Kowalski',
            signatureType: SignatureType::Qualified,
            priority: 1,                                  // kolejność podpisów
            phoneVerification: '+48 601 123 456',         // kod SMS przed podpisem
            returnUrl: 'https://app.example.com/podpisano',
            organizationName: 'Example sp. z o.o.', organizationTaxId: '7831693251', position: 'Prezes'),
        ParticipantRequest::signer('anna@example.com', 'Anna', 'Nowak', priority: 2),
        ParticipantRequest::viewer('biuro@example.com', 'Biuro', 'Obsługi'),
    ],
    language: 'pl',
    constraints: [Constraint::expiration(now()->addDays(14))],
), files: [
    storage_path('app/umowy/najem.pdf') => null,          // ścieżka => null albo 'nazwa.pdf' => $zawartość
]);

$document->id;       // "DOCUMENT_PROCESS:3429836f-..."
$document->status;   // DocumentStatus::Processing
```

Krok po kroku: `createDocument()` tworzy szkic, `addFile()` / `addFileFromPath()` dodają pliki źródłowe (łącznie
20 MB), `send()` wysyła dokument. Szkic zmienisz przez `updateDocument()`, `addParticipant()`, `removeParticipant()`
i `removeFile()`. Operacje zapisu nigdy nie są automatycznie ponawiane.

## Status, pliki i linki

```php
use Beziworld\Autenti\Data\DocumentFilter;
use Beziworld\Autenti\Enums\ListStatus;

$document = Autenti::document('3429836f-ba99-4b4c-a689-37f5e9f52106');   // prefiks jest dodawany, gdy go brak
$document->isCompleted();
foreach ($document->signers() as $signer) {
    $signer->email; $signer->status; $signer->hasCompleted();
}

Autenti::documents(new DocumentFilter(status: ListStatus::Completed, limit: 128, descending: true));

$signed = Autenti::downloadSignedFile($document->id);     // null, dopóki dokument nie jest zakończony
$signed?->saveTo(storage_path('app/podpisane/' . $signed->filename()));

Autenti::signingLink($document->id, $signer->partyId, redirectUrl: 'https://app.example.com/powrot');
Autenti::remind($document->id);       // wszyscy uczestnicy, którym można przypomnieć
Autenti::withdraw($document->id);
Autenti::history($document->id);      // lista Event
Autenti::availableActions($document->id);
```

## Callbacki i zdarzenia

Trasę pakietu rejestrujesz raz (organizacja może mieć do 5 callbacków; wymagany jest zakres
`notification_management` superadministratora):

```bash
php artisan autenti:callbacks --register                 # https://twoja-aplikacja/autenti/callback/default
php artisan autenti:callbacks --delete=CALLBACK_ADAPTER:API_V2:...
```

Autenti wysyła listę zdarzeń JSON z nagłówkiem `x-jws-signature`. Trasa weryfikuje odłączony JWS zestawem kluczy
środowiska (`autenti.com/developers/keys/webhook.jwks` albo `accept-webhook.jwks`, w cache przez dobę i pobierany
ponownie przy nieznanym kluczu) i wysyła zdarzenia:

| Zdarzenie | Typ zdarzenia Autenti |
|---|---|
| `AutentiEventReceived` | każde zdarzenie |
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
    // zapisz $file->contents
});
```

Callbacki obejmują całą organizację (trafiają też dokumenty wysłane z interfejsu Autenti) i są powtarzane aż do
HTTP 2xx (od razu, po 30 minutach, 6 i 24 godzinach), więc listenery muszą być idempotentne. Podrobione callbacki
dostają HTTP 403, błędne HTTP 400. Dla własnej trasy użyj `Autenti::parseCallback($rawBody, $signatureHeader)`.

## Wielu klientów i użytkowników

```php
Autenti::connection('druga-firma')->documents();
Autenti::asUser('anna@example.com')->createDocument($request);   // JWT bearer jako inny użytkownik organizacji
Autenti::using(['client_id' => ..., 'client_secret' => ..., 'grant' => 'client_credentials', 'name' => 'tenant-7']);
Autenti::resolveConnectionsUsing(fn (string $name): ?array => Tenant::firstWhere('slug', $name)?->autentiConfig());
```

## Błędy

| Wyjątek | Kiedy |
|---|---|
| `InvalidConfiguration` | brak client id, sekretu, e-maila użytkownika lub klucza prywatnego; nieznany grant, środowisko lub połączenie; nieużywalny klucz |
| `AuthenticationFailed` | odrzucone żądanie tokenu (`error()` np. `invalid_client`, `invalid_grant`; `requiresMultiFactor()`) |
| `ApiException` | błąd HTTP z `type()` Autenti (np. `/error/resource-not-found`), `instance()`, `retryAfter()` albo brak połączenia |
| `ChallengeRequired` | HTTP 403 z X-Challenge, na który wywołanie nie odpowiedziało; opis w `challenge()` |
| `InvalidRequest` | dane łamią udokumentowane ograniczenie (e-mail, NIP, tytuł, limit 128, offset 256, callback HTTPS) |
| `InvalidCallback` | brak lub błędny `x-jws-signature`, nieznany klucz, błędne body |

Wszystkie dziedziczą po `AutentiException`. Odczyty są ponawiane przy błędach połączenia i HTTP 5xx; HTTP 429 nigdy
nie jest ponawiany automatycznie, czego wymaga regulamin API.

## Rozwój

```bash
composer install
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pest
```

Testy generują jednorazowe klucze RSA w trakcie działania (phpseclib) i weryfikują przykład callbacku z oficjalnej
dokumentacji opublikowanym kluczem produkcyjnym.

## Autor

Pakiet tworzy i rozwija **Kamil Ćwiertnia**, [beziworld.eu](https://beziworld.eu), [biuro@beziworld.eu](mailto:biuro@beziworld.eu).
Zgłoszenia: [GitHub issues](https://github.com/beziworld/laravel-autenti/issues).
Potrzebujesz integracji? Zapraszam do kontaktu przez [beziworld.eu](https://beziworld.eu).

## Licencja

MIT. Zobacz [LICENSE.md](LICENSE.md). Pakiet nie jest powiązany z Autenti sp. z o.o.
