<?php

declare(strict_types=1);

use Beziworld\Autenti\Data\Constraint;
use Beziworld\Autenti\Data\DocumentFilter;
use Beziworld\Autenti\Data\DocumentRequest;
use Beziworld\Autenti\Data\ParticipantRequest;
use Beziworld\Autenti\Enums\DocumentStatus;
use Beziworld\Autenti\Enums\FilePurpose;
use Beziworld\Autenti\Enums\ListStatus;
use Beziworld\Autenti\Enums\ParticipantRole;
use Beziworld\Autenti\Enums\ParticipationStatus;
use Beziworld\Autenti\Enums\SignatureType;
use Beziworld\Autenti\Exceptions\ApiException;
use Beziworld\Autenti\Facades\Autenti;
use Beziworld\Autenti\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('reads a document process', function (): void {
    fakeAutenti(['*/document-processes/DOCUMENT_PROCESS:3429836f-ba99-4b4c-a689-37f5e9f52106' => Http::response(autentiFixture('document-process'))]);

    $document = Autenti::document('3429836f-ba99-4b4c-a689-37f5e9f52106');

    expect($document->status)->toBe(DocumentStatus::Completed)
        ->and($document->isCompleted())->toBeTrue()
        ->and($document->uuid())->toBe('3429836f-ba99-4b4c-a689-37f5e9f52106')
        ->and($document->language)->toBe('pl')
        ->and($document->flags)->toBe(['FLAG:VIEWED'])
        ->and($document->createdAt?->toIso8601String())->toBe('2026-11-10T09:00:00+01:00')
        ->and($document->signers())->toHaveCount(1)
        ->and($document->signedFile()?->id)->toBe('FILE-SIGNED_CONTENT_FILE:6a6d5fb9-2222-4f0e-8d3b-080182c03607');

    $signer = $document->participant('JOHN.DOE@example.com', ParticipantRole::Signer);

    expect($signer?->partyId)->toBe('PARTY-PUBLIC_SIGNING_PROCESS_ID:83961e68')
        ->and($signer?->status)->toBe(ParticipationStatus::Completed)
        ->and($signer?->hasCompleted())->toBeTrue()
        ->and($signer?->organizationName)->toBe('example organization')
        ->and($signer?->organizationTaxId)->toBe('7831693251')
        ->and($signer?->events[0]->shortType())->toBe('SIGNATURE_APPLICATION')
        ->and($document->participant('robot@example.com')?->currentUser)->toBeTrue();

    Http::assertSent(fn(Request $request): bool => $request->method() === 'GET'
        && $request->url() === TestCase::API . '/document-processes/DOCUMENT_PROCESS:3429836f-ba99-4b4c-a689-37f5e9f52106'
        && $request->hasHeader('Accept', 'application/json'));
});

it('lists document processes with filters', function (): void {
    fakeAutenti(['*/document-processes*' => Http::response([autentiFixture('document-process')])]);

    $documents = Autenti::documents(new DocumentFilter(
        status: ListStatus::Completed,
        limit: 128,
        offset: 300,
        descending: true,
        modifiedAfter: new DateTimeImmutable('2026-11-01 00:00:00', new DateTimeZone('Europe/Warsaw')),
        search: 'najmu',
        recipientEmail: 'john.doe@example.com',
    ));

    expect($documents)->toHaveCount(1)->and($documents[0]->title)->toBe('Umowa najmu 12/2026');

    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API
        . '/document-processes?status=COMPLETED&limit=128&offset=300&orderBy=MODIFIED&sort=DESCENDING'
        . '&modifiedAfter=2026-10-31T23%3A00%3A00Z&freeTextSearch=najmu&recipientEmail=john.doe%40example.com');
});

it('creates a draft with participants and constraints', function (): void {
    fakeAutenti(['*/document-processes' => Http::response(['id' => 'DOCUMENT_PROCESS:00000000-0000-0000-0000-000000000000', 'title' => 'Umowa', 'status' => 'DRAFT'])]);

    $draft = Autenti::createDocument(new DocumentRequest(
        title: 'Umowa',
        participants: [
            ParticipantRequest::signer('john.doe@example.com', 'John', 'Doe', SignatureType::Qualified, priority: 1, phoneVerification: '601 123 456', returnUrl: 'https://app.example.com/signed', organizationName: 'example organization', organizationTaxId: '783-169-32-51', position: 'CEO'),
            ParticipantRequest::approver('mary@example.com', 'Mary', 'Smith', priority: 2),
            ParticipantRequest::viewer('office@example.com', 'Office', 'Team'),
        ],
        description: 'Prosimy o podpis',
        constraints: [Constraint::expiration(new DateTimeImmutable('2026-12-31 23:59:59', new DateTimeZone('UTC')))],
    ));

    expect($draft->status)->toBe(DocumentStatus::Draft);

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== TestCase::API . '/document-processes') {
            return false;
        }

        $body = json_decode($request->body(), true);
        $signer = $body['parties'][0];

        return $request->method() === 'POST'
            && $body['title'] === 'Umowa'
            && $body['processLanguage'] === 'pl'
            && $body['description'] === 'Prosimy o podpis'
            && $signer['role'] === 'SIGNER'
            && $signer['party']['contacts'] === [['type' => 'CONTACT-TYPE:EMAIL', 'attributes' => ['email' => 'john.doe@example.com']]]
            && $signer['party']['relationships'] === [[
                'type' => 'PARTY_RELATIONSHIP-TYPE:MEMBER',
                'party' => ['name' => 'example organization', 'extIds' => [['identificationSpace' => 'TAXID-PL-NIP', 'identifier' => '7831693251']]],
                'attributes' => ['relationshipDescription' => 'CEO'],
            ]]
            && $signer['constraints'] === [
                ['constrainedActions' => ['ACTION:SIGNATURE_APPLICATION'], 'classifiers' => ['CONSTRAINT-UNIQUE_TYPE:SIGNATURE_TYPE'], 'attributes' => ['requiredClassifiers' => ['SIGNATURE_PROVIDER-SIGNATURE_TYPE:QUALIFIED']]],
                ['constrainedActions' => [], 'classifiers' => ['CONSTRAINT-UNIQUE_TYPE:PARTICIPATION_PRIORITY'], 'attributes' => ['priority' => 1]],
                ['constrainedActions' => ['ACTION:SIGNATURE_APPLICATION'], 'classifiers' => ['CONSTRAINT-UNIQUE_TYPE:PHONE_NUMBER_VERIFICATION_REQUIRED'], 'attributes' => ['phoneNumber' => '+48601123456']],
                ['classifiers' => ['CONSTRAINT-UNIQUE_TYPE:UI_INTERACTION_END_RETURN_URL'], 'attributes' => ['url' => 'https://app.example.com/signed']],
            ]
            && $body['parties'][1]['role'] === 'APPROVER'
            && $body['parties'][2] === ['party' => ['firstName' => 'Office', 'lastName' => 'Team', 'contacts' => [['type' => 'CONTACT-TYPE:EMAIL', 'attributes' => ['email' => 'office@example.com']]]], 'role' => 'VIEWER']
            && $body['constraints'][0]['attributes'] === ['expirationTime' => '2026-12-31T23:59:59Z', 'notifyBeforeExpiration' => true];
    });
});

it('creates, uploads and sends a document in one call', function (): void {
    $id = 'DOCUMENT_PROCESS:11111111-2222-3333-4444-555555555555';
    fakeAutenti([
        '*/document-processes/' . $id . '/files' => Http::response(['id' => 'FILE-SOURCE_FILE:aaaa', 'filename' => 'umowa.pdf', 'version' => '1', 'filePurpose' => 'SOURCE_FILE', 'mimeType' => 'application/pdf']),
        '*/document-processes/' . $id . '/actions' => Http::response([['id' => 'EVENT-AUTENTI:x', 'eventType' => 'EVENT_CLASSIFIER-UNIQUE_TYPE:DOCUMENT_SENT', 'object' => ['id' => $id]]]),
        '*/document-processes/' . $id => Http::response(['id' => $id, 'status' => 'PROCESSING']),
        '*/document-processes' => Http::response(['id' => $id, 'status' => 'DRAFT']),
    ]);

    $document = Autenti::sendDocument(new DocumentRequest('Umowa', [ParticipantRequest::signer('john.doe@example.com', 'John', 'Doe')]), ['umowa.pdf' => '%PDF-1.4 test']);

    expect($document->status)->toBe(DocumentStatus::Processing);

    $methods = array_map(fn(array $pair): string => $pair[0]->method() . ' ' . str_replace(TestCase::API, '', $pair[0]->url()), apiRequests());

    expect($methods)->toBe([
        'POST /document-processes',
        'POST /document-processes/' . $id . '/files',
        'POST /document-processes/' . $id . '/actions',
        'GET /document-processes/' . $id,
    ]);

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/files')) {
            return false;
        }

        $parts = collect($request->data())->keyBy('name');

        return $request->isMultipart()
            && json_decode((string) $parts['fileMeta']['contents'], true) === ['filename' => 'umowa.pdf', 'filePurpose' => 'SOURCE_FILE', 'mimeType' => 'application/pdf']
            && ($parts['fileMeta']['headers']['Content-Type'] ?? null) === 'application/json'
            && $parts['file']['contents'] === '%PDF-1.4 test'
            && ($parts['file']['filename'] ?? null) === 'umowa.pdf'
            && ($parts['file']['headers']['Content-Type'] ?? null) === 'application/pdf';
    });
});

it('updates and manages participants of a draft', function (): void {
    $id = 'DOCUMENT_PROCESS:abc';
    fakeAutenti([
        '*/document-processes/' . $id . '/parties/PARTY-EMAIL:john.doe@example.com' => Http::response('', 204),
        '*/document-processes/' . $id . '/parties' => Http::response(autentiFixture('document-process')['parties'][0]),
        '*/document-processes/' . $id => Http::response(['id' => $id, 'title' => 'Nowy tytuł', 'status' => 'DRAFT']),
    ]);

    expect(Autenti::updateDocument($id, title: 'Nowy tytuł')->title)->toBe('Nowy tytuł')
        ->and(Autenti::addParticipant($id, ParticipantRequest::signer('john.doe@example.com', 'John', 'Doe'))->role)->toBe(ParticipantRole::Signer);

    Autenti::removeParticipant($id, 'PARTY-EMAIL:john.doe@example.com');

    Http::assertSent(fn(Request $request): bool => $request->method() === 'PATCH' && $request->data() === ['title' => 'Nowy tytuł']);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === TestCase::API . '/document-processes/' . $id . '/parties/PARTY-EMAIL:john.doe@example.com');
});

it('lists, downloads and removes files', function (): void {
    $id = 'DOCUMENT_PROCESS:3429836f-ba99-4b4c-a689-37f5e9f52106';
    $signed = 'FILE-SIGNED_CONTENT_FILE:6a6d5fb9-2222-4f0e-8d3b-080182c03607';
    fakeAutenti([
        '*/files/' . $signed . '/content' => Http::response('%PDF signed', 200, ['Content-Type' => 'application/pdf']),
        '*/files/FILE-SOURCE_FILE:x' => Http::response('', 204),
        '*/document-processes/' . $id . '/files' => Http::response(autentiFixture('document-process')['files']),
    ]);

    $download = Autenti::downloadSignedFile($id);

    expect($download?->contents)->toBe('%PDF signed')
        ->and($download?->filename())->toBe('umowa-signed.pdf')
        ->and($download?->mimeType)->toBe('application/pdf')
        ->and($download?->file->purpose)->toBe(FilePurpose::Signed)
        ->and($download?->size())->toBe(11);

    $path = tempnam(sys_get_temp_dir(), 'autenti');
    expect($download?->saveTo($path))->toBe(11)->and(file_get_contents($path))->toBe('%PDF signed');
    @unlink($path);

    Autenti::removeFile($id, 'FILE-SOURCE_FILE:x');

    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/content') && $request->hasHeader('Accept', '*/*'));
    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE');
});

it('pins the file version with If-Match', function (): void {
    fakeAutenti(['*/content' => Http::response('bytes')]);

    expect(Autenti::fileContent('abc', 'FILE-SOURCE_FILE:1', version: '2'))->toBe('bytes');

    Http::assertSent(fn(Request $request): bool => $request->hasHeader('If-Match', '2'));
});

it('returns null when the document has no signed file yet', function (): void {
    fakeAutenti(['*/files' => Http::response([autentiFixture('document-process')['files'][0]])]);

    expect(Autenti::downloadSignedFile('abc'))->toBeNull();
});

it('retries reads on server errors and connection failures but never writes', function (): void {
    Http::fake(['*/auth/token' => Http::response(autentiToken())]);
    Http::fakeSequence('*/document-processes/*')
        ->pushStatus(502)
        ->pushFailedConnection()
        ->push(['id' => 'DOCUMENT_PROCESS:abc', 'status' => 'DRAFT']);

    expect(Autenti::document('abc')->id)->toBe('DOCUMENT_PROCESS:abc');
    expect(apiRequests())->toHaveCount(3);
});

it('sends a write exactly once', function (): void {
    fakeAutenti(['*/document-processes' => Http::response(['type' => '/error/internal', 'title' => 'Internal error', 'status' => 500], 500)]);

    expect(fn() => Autenti::createDocument(new DocumentRequest('Umowa')))->toThrow(ApiException::class, 'Internal error');
    expect(apiRequests())->toHaveCount(1);
});

it('reports transport failures', function (): void {
    Http::fake(['*/auth/token' => Http::response(autentiToken()), '*' => fn() => throw new ConnectionException('timed out')]);

    Autenti::document('abc');
})->throws(ApiException::class, 'Could not connect to Autenti: timed out');

it('reports documented error bodies', function (): void {
    fakeAutenti(['*' => Http::response(['type' => '/error/resource-not-found', 'title' => 'Resource not found', 'status' => 404, 'detail' => 'No such document', 'instance' => 'abc-123'], 404)]);

    try {
        Autenti::document('missing');
        $this->fail('Expected ApiException.');
    } catch (ApiException $exception) {
        expect($exception->isNotFound())->toBeTrue()
            ->and($exception->type())->toBe('/error/resource-not-found')
            ->and($exception->isType('/error/resource'))->toBeTrue()
            ->and($exception->instance())->toBe('abc-123')
            ->and($exception->getMessage())->toBe('Autenti error (HTTP 404): Resource not found: No such document [/error/resource-not-found]');
    }
});

it('does not retry rate limited requests', function (): void {
    fakeAutenti(['*/document-processes*' => Http::response(['title' => 'Too many requests', 'status' => 429], 429, ['Retry-After' => '120'])]);

    try {
        Autenti::documents();
        $this->fail('Expected ApiException.');
    } catch (ApiException $exception) {
        expect($exception->isRateLimited())->toBeTrue()->and($exception->retryAfter())->toBe(120);
    }

    expect(apiRequests())->toHaveCount(1);
});

it('uses the production host', function (): void {
    Http::fake(['api.autenti.com/api/v2/auth/token' => Http::response(autentiToken()), 'api.autenti.com/api/v2/document-processes*' => Http::response([])]);

    Autenti::using(['client_id' => 'p', 'client_secret' => 's', 'grant' => 'client_credentials', 'environment' => 'production'])->documents();

    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://api.autenti.com/api/v2/document-processes');
});
