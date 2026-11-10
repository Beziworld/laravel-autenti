<?php

declare(strict_types=1);

use Beziworld\Autenti\Data\Event;
use Beziworld\Autenti\Exceptions\ChallengeRequired;
use Beziworld\Autenti\Facades\Autenti;
use Beziworld\Autenti\Support\Base64Url;
use Beziworld\Autenti\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const DOCUMENT = 'DOCUMENT_PROCESS:3429836f-ba99-4b4c-a689-37f5e9f52106';

/**
 * Decoded X-ASSERTION headers of a request.
 *
 * @return list<array<array-key, mixed>>
 */
function assertions(Request $request): array
{
    return array_map(fn(string $header): array => json_decode((string) Base64Url::decode($header), true), $request->header('X-ASSERTION'));
}

function challengeHeader(array $challenge): string
{
    return base64_encode(json_encode($challenge));
}

function sentEvent(string $type): array
{
    return [[
        'timestamp' => '2026-01-01T00:00:00.000Z',
        'id' => 'EVENT-AUTENTI:xxxxxxxx',
        'eventType' => $type,
        'classifiers' => ['EVENT_CLASSIFIER-PROCESS:SUBMISSION'],
        'actor' => ['id' => 'PARTY-EMAIL:john.doe@exampple.com'],
        'object' => ['id' => DOCUMENT, 'type' => 'EVENT_OBJECT-TYPE:DOCUMENT_PROCESS'],
        'attributes' => [],
    ]];
}

it('sends a document with the documented action assertion', function (): void {
    fakeAutenti(['*/actions' => Http::response(sentEvent(Event::DOCUMENT_SENT))]);

    $events = Autenti::send(DOCUMENT);

    expect($events[0]->is(Event::DOCUMENT_SENT))->toBeTrue()
        ->and($events[0]->is('DOCUMENT_SENT'))->toBeTrue()
        ->and($events[0]->documentId())->toBe(DOCUMENT)
        ->and($events[0]->timestamp?->toIso8601String())->toBe('2026-01-01T01:00:00+01:00');

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
        && $request->url() === TestCase::API . '/document-processes/' . DOCUMENT . '/actions'
        && assertions($request) === [[
            'classifiers' => ['CHALLENGE_CLASSIFIER-UNIQUE_TYPE:ACTION_SELECTION'],
            'attributes' => ['selectedIds' => ['EVENT_CLASSIFIER-UNIQUE_TYPE:DOCUMENT_SENT']],
        ]]);
});

it('produces the documented assertion bytes', function (): void {
    fakeAutenti(['*/actions' => Http::response(sentEvent(Event::DOCUMENT_WITHDRAWAL))]);

    Autenti::withdraw(DOCUMENT);

    $documented = 'eyJjbGFzc2lmaWVycyI6WyJDSEFMTEVOR0VfQ0xBU1NJRklFUi1VTklRVUVfVFlQRTpBQ1RJT05fU0VMRUNUSU9OIl0sImF0dHJpYnV0ZXMiOnsic2VsZWN0ZWRJZHMiOlsiRVZFTlRfQ0xBU1NJRklFUi1VTklRVUVfVFlQRTpET0NVTUVOVF9XSVRIRFJBV0FMIl19fQ==';

    Http::assertSent(fn(Request $request): bool => $request->hasHeader('X-ASSERTION') && $request->header('X-ASSERTION')[0] === rtrim($documented, '='));
});

it('discovers the available actions from the challenge', function (): void {
    $challenge = [
        'classifiers' => ['CHALLENGE_CLASSIFIER-UNIQUE_TYPE:ACTION_SELECTION', 'CHALLENGE_CLASSIFIER-USER_INTERACTION_TYPE:SELECTION'],
        'attributes' => ['options' => [
            ['id' => 'EVENT_CLASSIFIER-UNIQUE_TYPE:DOCUMENT_WITHDRAWAL', 'description' => 'Document withdrawal', 'availability' => 'AVAILABLE', 'meta' => null],
            ['id' => 'EVENT_CLASSIFIER-UNIQUE_TYPE:DOCUMENT_REMINDER_SENT', 'description' => 'Document reminder sent', 'availability' => 'UNAVAILABLE', 'meta' => null],
        ]],
    ];
    fakeAutenti(['*/actions' => Http::response(['type' => '/challenge', 'title' => 'Challenge requested', 'status' => 403], 403, ['x-challenge' => challengeHeader($challenge)])]);

    $options = Autenti::availableActions(DOCUMENT);

    expect($options)->toHaveCount(2)
        ->and($options[0]->shortId())->toBe('DOCUMENT_WITHDRAWAL')
        ->and($options[0]->isAvailable())->toBeTrue()
        ->and($options[1]->isAvailable())->toBeFalse();

    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/actions') && ! $request->hasHeader('X-ASSERTION') && $request->body() === '{}');
});

it('raises a challenge exception for unanswered challenges', function (): void {
    fakeAutenti(['*/actions' => Http::response(['type' => '/challenge', 'status' => 403], 403, ['X-Challenge' => challengeHeader(['classifiers' => ['CHALLENGE_CLASSIFIER-UNIQUE_TYPE:CONSENT']])])]);

    try {
        Autenti::send(DOCUMENT);
        $this->fail('Expected ChallengeRequired.');
    } catch (ChallengeRequired $exception) {
        expect($exception->challenge()->is('CHALLENGE_CLASSIFIER-UNIQUE_TYPE:CONSENT'))->toBeTrue()
            ->and($exception->payload()['type'])->toBe('/challenge');
    }
});

it('reminds every available participant in two steps', function (): void {
    $selection = [
        'classifiers' => ['CHALLENGE_CLASSIFIER-UNIQUE_TYPE:DOCUMENT_REMINDER_PARTY_SELECTION', 'CHALLENGE_CLASSIFIER-USER_INTERACTION_TYPE:SELECTION'],
        'attributes' => ['mode' => 'multi', 'options' => [
            ['id' => 'PARTY-PUBLIC_SIGNING_PROCESS_ID:83961e68', 'description' => 'John Doe / john.doe@example.com', 'availability' => 'AVAILABLE', 'meta' => null],
            ['id' => 'PARTY-PUBLIC_SIGNING_PROCESS_ID:10aed26e', 'description' => 'Mary Second / mary.second@example.com', 'availability' => 'AVAILABLE', 'meta' => null],
            ['id' => 'PARTY-PUBLIC_SIGNING_PROCESS_ID:99999999', 'description' => 'Signed already', 'availability' => 'UNAVAILABLE', 'meta' => null],
        ]],
    ];
    Http::fake(['*/auth/token' => Http::response(autentiToken())]);
    Http::fakeSequence('*/actions')
        ->push(['type' => '/challenge', 'status' => 403], 403, ['x-challenge' => challengeHeader($selection)])
        ->push([]);

    expect(Autenti::remind(DOCUMENT))->toBe([]);

    $last = collect(apiRequests())->last()[0];

    expect(assertions($last))->toBe([
        ['classifiers' => ['CHALLENGE_CLASSIFIER-UNIQUE_TYPE:ACTION_SELECTION'], 'attributes' => ['selectedIds' => ['EVENT_CLASSIFIER-UNIQUE_TYPE:DOCUMENT_REMINDER_SENT']]],
        ['classifiers' => ['CHALLENGE_CLASSIFIER-UNIQUE_TYPE:DOCUMENT_REMINDER_PARTY_SELECTION'], 'attributes' => ['selectedIds' => ['PARTY-PUBLIC_SIGNING_PROCESS_ID:83961e68', 'PARTY-PUBLIC_SIGNING_PROCESS_ID:10aed26e']]],
    ]);
});

it('reminds the given participants directly', function (): void {
    fakeAutenti(['*/actions' => Http::response([])]);

    Autenti::remind(DOCUMENT, ['PARTY-PUBLIC_SIGNING_PROCESS_ID:83961e68']);

    expect(apiRequests())->toHaveCount(1);
    Http::assertSent(fn(Request $request): bool => $request->hasHeader('X-ASSERTION') && count($request->header('X-ASSERTION')) === 2);
});

it('reads the action history', function (): void {
    fakeAutenti(['*/actions' => Http::response(autentiFixture('document-process')['parties'][0]['events'])]);

    expect(Autenti::history(DOCUMENT)[0]->type)->toBe(Event::SIGNATURE_APPLICATION);

    Http::assertSent(fn(Request $request): bool => $request->method() === 'GET');
});

it('obtains action links with a redirect URL', function (): void {
    $link = fn(string $action): array => [
        'classifiers' => ['CHALLENGE_CLASSIFIER-UNIQUE_TYPE:SIGNATURE_UI_REDIRECT', 'CHALLENGE_CLASSIFIER-USER_INTERACTION_TYPE:HTTP_REQUEST'],
        'attributes' => ['userInteraction' => 'browser', 'httpRequest' => ['uri' => 'https://sign.autenti.com/enter/XYZ', 'method' => 'GET'], 'description' => 'Execute the described request in a browser', 'actionIdentifier' => $action],
    ];
    fakeAutenti(['*/action-links' => Http::response([$link(Event::SIGNATURE_REJECTION), $link(Event::SIGNATURE_APPLICATION)])]);

    $links = Autenti::actionLinks(DOCUMENT, 'PARTY-PUBLIC_SIGNING_PROCESS_ID:83961e68', 'https://app.example.com/back?id=1');

    expect($links)->toHaveCount(2)
        ->and($links[1]->isSigning())->toBeTrue()
        ->and($links[1]->method)->toBe('GET')
        ->and(Autenti::signingLink(DOCUMENT, 'PARTY-PUBLIC_SIGNING_PROCESS_ID:83961e68'))->toBe('https://sign.autenti.com/enter/XYZ');

    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/document-processes/' . DOCUMENT . '/parties/PARTY-PUBLIC_SIGNING_PROCESS_ID:83961e68/action-links'
        && $request->data() === ['redirectUrl' => 'https://app.example.com/back?id=1']);
});

it('manages callbacks of the organisation', function (): void {
    fakeAutenti([
        '*/applications/callbacks/CALLBACK_ADAPTER:API_V2:c96e793b' => Http::response('', 204),
        '*/applications/callbacks' => Http::sequence()
            ->push(['id' => 'CALLBACK_ADAPTER:API_V2:c96e793b', 'callbackAdapterId' => 'CALLBACK_ADAPTER:API_V2', 'callbackParameters' => ['callbackUrl' => 'https://app.example.com/autenti/callback/default']], 201)
            ->push([['id' => 'CALLBACK_ADAPTER:API_V2:c96e793b', 'callbackAdapterId' => 'CALLBACK_ADAPTER:API_V2', 'callbackParameters' => ['callbackUrl' => 'https://app.example.com/autenti/callback/default']]]),
    ]);

    $callback = Autenti::registerCallback();

    expect($callback->id)->toBe('CALLBACK_ADAPTER:API_V2:c96e793b')
        ->and($callback->url)->toBe('https://app.example.com/autenti/callback/default')
        ->and(Autenti::callbacks())->toHaveCount(1);

    Autenti::deleteCallback('CALLBACK_ADAPTER:API_V2:c96e793b');

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/applications/callbacks')
        && $request->data() === ['callbackAdapterId' => 'CALLBACK_ADAPTER:API_V2', 'callbackParameters' => ['callbackUrl' => 'https://app.example.com/autenti/callback/default']]);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE');
});

it('refuses plain HTTP callback URLs', function (): void {
    Http::fake();

    Autenti::registerCallback('http://app.example.com/hook');
})->throws(Beziworld\Autenti\Exceptions\InvalidRequest::class, 'HTTPS');
