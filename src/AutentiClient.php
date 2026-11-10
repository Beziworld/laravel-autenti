<?php

declare(strict_types=1);

namespace Beziworld\Autenti;

use Beziworld\Autenti\Auth\TokenProvider;
use Beziworld\Autenti\Data\ActionLink;
use Beziworld\Autenti\Data\ActionOption;
use Beziworld\Autenti\Data\CallbackDefinition;
use Beziworld\Autenti\Data\Challenge;
use Beziworld\Autenti\Data\DocumentFilter;
use Beziworld\Autenti\Data\DocumentProcess;
use Beziworld\Autenti\Data\DocumentRequest;
use Beziworld\Autenti\Data\DownloadedFile;
use Beziworld\Autenti\Data\Event;
use Beziworld\Autenti\Data\FileInfo;
use Beziworld\Autenti\Data\Participant;
use Beziworld\Autenti\Data\ParticipantRequest;
use Beziworld\Autenti\Enums\FilePurpose;
use Beziworld\Autenti\Exceptions\ApiException;
use Beziworld\Autenti\Exceptions\ChallengeRequired;
use Beziworld\Autenti\Exceptions\InvalidCallback;
use Beziworld\Autenti\Exceptions\InvalidRequest;
use Beziworld\Autenti\Support\Base64Url;
use Beziworld\Autenti\Support\CallbackVerifier;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use JsonException;

/**
 * Client of the Autenti Document Process API v2 for one API client application.
 *
 * Requests carry a bearer token of the configured grant. Writes (creation,
 * modification, files, actions, callbacks) are sent once; the only
 * repetition is a single resend after HTTP 401 with a fresh token. Reads
 * are retried on connection errors and HTTP 5xx. HTTP 429 is reported with
 * Retry-After and never retried automatically, as the API terms require.
 */
class AutentiClient
{
    /**
     * @param (Closure(AutentiConfig): ?string)|null $callbackUrlResolver Builds the default callback URL of the connection.
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly TokenProvider $tokens,
        private readonly CallbackVerifier $verifier,
        private readonly AutentiConfig $config,
        private readonly ?Closure $callbackUrlResolver = null,
    ) {}

    public function config(): AutentiConfig
    {
        return $this->config;
    }

    public function connectionName(): ?string
    {
        return $this->config->name;
    }

    /**
     * Current access token (cached).
     */
    public function token(): string
    {
        return $this->tokens->token();
    }

    public function forgetToken(): void
    {
        $this->tokens->forget();
    }

    /**
     * Document processes visible to the authenticated user, one page.
     *
     * @return list<DocumentProcess>
     */
    public function documents(?DocumentFilter $filter = null): array
    {
        $payload = $this->call('GET', '/document-processes', query: ($filter ?? new DocumentFilter())->toQuery(), retry: true);

        return array_map(fn(array $item): DocumentProcess => DocumentProcess::fromArray($item, $this->config->timezone), $this->listOfArrays($payload));
    }

    public function document(string $documentId): DocumentProcess
    {
        return $this->documentFrom($this->call('GET', '/document-processes/' . $this->documentPath($documentId), retry: true));
    }

    /**
     * Creates a draft; add files and send it afterwards, or use sendDocument().
     */
    public function createDocument(DocumentRequest $request): DocumentProcess
    {
        return $this->documentFrom($this->call('POST', '/document-processes', json: $request->toArray($this->config->language)));
    }

    /**
     * Changes the title, description or language of a draft.
     */
    public function updateDocument(string $documentId, ?string $title = null, ?string $description = null, ?string $language = null): DocumentProcess
    {
        $body = array_filter(['title' => $title, 'description' => $description, 'processLanguage' => $language], static fn(?string $value): bool => $value !== null);

        if ($body === []) {
            throw InvalidRequest::because('nothing to update');
        }

        return $this->documentFrom($this->call('PATCH', '/document-processes/' . $this->documentPath($documentId), json: $body));
    }

    /**
     * Creates a draft, uploads the files and sends the document to the participants.
     *
     * Files are given as path => null (name taken from the path) or as
     * filename => contents. The returned process is read again after
     * sending. When a later step fails, the draft stays in Autenti and the
     * exception is raised; its identifier is available through
     * AutentiClient::documents() with the DRAFT status.
     *
     * @param array<string, string|null> $files
     */
    public function sendDocument(DocumentRequest $request, array $files): DocumentProcess
    {
        if ($files === []) {
            throw InvalidRequest::because('at least one file is required to send a document');
        }

        $document = $this->createDocument($request);

        foreach ($files as $name => $contents) {
            if ($contents === null) {
                $this->addFileFromPath($document->id, $name);
            } else {
                $this->addFile($document->id, $contents, $name);
            }
        }

        $this->send($document->id);

        return $this->document($document->id);
    }

    /**
     * @return list<Participant>
     */
    public function participants(string $documentId): array
    {
        $payload = $this->call('GET', '/document-processes/' . $this->documentPath($documentId) . '/parties', retry: true);

        return Participant::listFromArray($this->listOfArrays($payload), $this->config->timezone);
    }

    public function addParticipant(string $documentId, ParticipantRequest $participant): Participant
    {
        $payload = $this->call('POST', '/document-processes/' . $this->documentPath($documentId) . '/parties', json: $participant->toArray());

        return Participant::fromArray(is_array($payload) ? $payload : [], $this->config->timezone);
    }

    /**
     * Removes a party from a draft.
     */
    public function removeParticipant(string $documentId, string $partyId): void
    {
        $this->call('DELETE', '/document-processes/' . $this->documentPath($documentId) . '/parties/' . $this->path($partyId));
    }

    /**
     * @return list<FileInfo>
     */
    public function files(string $documentId): array
    {
        $payload = $this->call('GET', '/document-processes/' . $this->documentPath($documentId) . '/files', retry: true);

        return FileInfo::listFromArray($this->listOfArrays($payload), $this->config->timezone);
    }

    public function file(string $documentId, string $fileId): FileInfo
    {
        $payload = $this->call('GET', '/document-processes/' . $this->documentPath($documentId) . '/files/' . $this->path($fileId), retry: true);

        return FileInfo::fromArray(is_array($payload) ? $payload : [], $this->config->timezone);
    }

    /**
     * Uploads a source file to a draft (multipart parts "fileMeta" and "file").
     *
     * All source files are combined into one document when it is sent; the
     * total size may not exceed 20 MB (2 MB for each xls/xlsx file).
     */
    public function addFile(string $documentId, string $contents, string $filename, ?string $mimeType = null, FilePurpose $purpose = FilePurpose::Source): FileInfo
    {
        if ($contents === '') {
            throw InvalidRequest::because('file content must not be empty');
        }

        if (mb_strlen($filename) > 150) {
            throw InvalidRequest::because('file name may have at most 150 characters');
        }

        $mimeType ??= self::guessMimeType($filename);
        $meta = json_encode(['filename' => $filename, 'filePurpose' => $purpose->value, 'mimeType' => $mimeType], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $payload = $this->call(
            'POST',
            '/document-processes/' . $this->documentPath($documentId) . '/files',
            multipart: static fn(PendingRequest $request): PendingRequest => $request
                ->attach('fileMeta', $meta, null, ['Content-Type' => 'application/json'])
                ->attach('file', $contents, $filename, ['Content-Type' => $mimeType]),
        );

        return FileInfo::fromArray(is_array($payload) ? $payload : [], $this->config->timezone);
    }

    public function addFileFromPath(string $documentId, string $path, ?string $filename = null, ?string $mimeType = null): FileInfo
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw InvalidRequest::because(sprintf('file "%s" is not readable', $path));
        }

        return $this->addFile($documentId, (string) file_get_contents($path), $filename ?? basename($path), $mimeType);
    }

    public function removeFile(string $documentId, string $fileId): void
    {
        $this->call('DELETE', '/document-processes/' . $this->documentPath($documentId) . '/files/' . $this->path($fileId));
    }

    /**
     * Binary content of a file; $version pins the expected version (If-Match).
     */
    public function fileContent(string $documentId, string $fileId, ?string $version = null): string
    {
        return $this->download($documentId, $fileId, $version)->body();
    }

    public function downloadFile(string $documentId, FileInfo|string $file, ?string $version = null): DownloadedFile
    {
        $info = $file instanceof FileInfo ? $file : $this->file($documentId, $file);
        $response = $this->download($documentId, $info->id, $version);
        $type = $response->header('Content-Type');

        return new DownloadedFile($info, $response->body(), $type !== '' ? $type : $info->mimeType);
    }

    /**
     * The signed result (SIGNED_CONTENT_FILE) of a completed document, or null when it does not exist yet.
     */
    public function downloadSignedFile(string $documentId): ?DownloadedFile
    {
        foreach ($this->files($documentId) as $file) {
            if ($file->isSigned()) {
                return $this->downloadFile($documentId, $file);
            }
        }

        return null;
    }

    /**
     * Actions the authenticated user may perform on the document now.
     *
     * @return list<ActionOption>
     */
    public function availableActions(string $documentId): array
    {
        try {
            $this->call('POST', '/document-processes/' . $this->documentPath($documentId) . '/actions', json: []);
        } catch (ChallengeRequired $exception) {
            return $exception->challenge()->options();
        }

        return [];
    }

    /**
     * Performs an action selected by its event type identifier, e.g. Event::DOCUMENT_SENT.
     *
     * Additional assertions answer further challenges of the action (for
     * example the participant selection of reminders).
     *
     * @param list<array<string, mixed>> $assertions
     * @return list<Event>
     *
     * @throws ChallengeRequired When Autenti needs more information.
     */
    public function performAction(string $documentId, string $action, array $assertions = []): array
    {
        $headers = [Challenge::selection(Challenge::ACTION_SELECTION, [$action]), ...$assertions];
        $encoded = array_map(static fn(array $assertion): string => Base64Url::encode(json_encode($assertion, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $headers);

        $payload = $this->call('POST', '/document-processes/' . $this->documentPath($documentId) . '/actions', json: [], headers: ['X-ASSERTION' => $encoded]);

        return Event::listFromArray(is_array($payload) ? $payload : [], $this->config->timezone);
    }

    /**
     * Sends a draft to its participants; at least one file is required.
     *
     * @return list<Event>
     */
    public function send(string $documentId): array
    {
        return $this->performAction($documentId, Event::DOCUMENT_SENT);
    }

    /**
     * Withdraws a sent document.
     *
     * @return list<Event>
     */
    public function withdraw(string $documentId): array
    {
        return $this->performAction($documentId, Event::DOCUMENT_WITHDRAWAL);
    }

    /**
     * Sends an e-mail reminder; without party identifiers every participant available for a reminder is selected.
     *
     * @param list<string>|null $partyIds Identifiers such as "PARTY-PUBLIC_SIGNING_PROCESS_ID:83961e68".
     * @return list<Event>
     */
    public function remind(string $documentId, ?array $partyIds = null): array
    {
        if ($partyIds === null) {
            try {
                return $this->performAction($documentId, Event::DOCUMENT_REMINDER_SENT);
            } catch (ChallengeRequired $exception) {
                if (! $exception->challenge()->is(Challenge::REMINDER_PARTY_SELECTION)) {
                    throw $exception;
                }

                $partyIds = array_map(
                    static fn(ActionOption $option): string => $option->id,
                    array_values(array_filter($exception->challenge()->options(), static fn(ActionOption $option): bool => $option->isAvailable())),
                );
            }

            if ($partyIds === []) {
                return [];
            }
        }

        return $this->performAction($documentId, Event::DOCUMENT_REMINDER_SENT, [Challenge::selection(Challenge::REMINDER_PARTY_SELECTION, $partyIds)]);
    }

    /**
     * Events registered on the document (its action history).
     *
     * @return list<Event>
     */
    public function history(string $documentId): array
    {
        $payload = $this->call('GET', '/document-processes/' . $this->documentPath($documentId) . '/actions', retry: true);

        return Event::listFromArray(is_array($payload) ? $payload : [], $this->config->timezone);
    }

    /**
     * Entry links of a participant of a sent document; $redirectUrl receives the participant afterwards.
     *
     * @return list<ActionLink>
     */
    public function actionLinks(string $documentId, string $partyId, ?string $redirectUrl = null): array
    {
        $payload = $this->call(
            'POST',
            '/document-processes/' . $this->documentPath($documentId) . '/parties/' . $this->path($partyId) . '/action-links',
            json: $redirectUrl !== null ? ['redirectUrl' => $redirectUrl] : [],
        );

        return array_map(static fn(array $item): ActionLink => ActionLink::fromArray($item), $this->listOfArrays($payload));
    }

    /**
     * Signing link of a participant, or null when the participant cannot sign.
     */
    public function signingLink(string $documentId, string $partyId, ?string $redirectUrl = null): ?string
    {
        foreach ($this->actionLinks($documentId, $partyId, $redirectUrl) as $link) {
            if ($link->isSigning()) {
                return $link->uri;
            }
        }

        return null;
    }

    /**
     * Callbacks of the organisation (requires the notification_management scope).
     *
     * @return list<CallbackDefinition>
     */
    public function callbacks(): array
    {
        return array_map(static fn(array $item): CallbackDefinition => CallbackDefinition::fromArray($item), $this->listOfArrays($this->call('GET', '/applications/callbacks', retry: true)));
    }

    public function callback(string $callbackId): CallbackDefinition
    {
        $payload = $this->call('GET', '/applications/callbacks/' . $this->path($callbackId), retry: true);

        return CallbackDefinition::fromArray(is_array($payload) ? $payload : []);
    }

    /**
     * Registers a callback URL for the organisation (at most 5); defaults to the package route of the connection.
     */
    public function registerCallback(?string $url = null): CallbackDefinition
    {
        $url ??= $this->callbackUrl() ?? throw InvalidRequest::because('no callback URL given and the package route is disabled');

        if (! str_starts_with(strtolower($url), 'https://')) {
            throw InvalidRequest::because('callback URL must use HTTPS');
        }

        $payload = $this->call('POST', '/applications/callbacks', json: [
            'callbackAdapterId' => CallbackDefinition::ADAPTER_API_V2,
            'callbackParameters' => ['callbackUrl' => $url],
        ]);

        return CallbackDefinition::fromArray(is_array($payload) ? $payload : []);
    }

    public function deleteCallback(string $callbackId): void
    {
        $this->call('DELETE', '/applications/callbacks/' . $this->path($callbackId));
    }

    /**
     * Callback URL of the connection: the package route, when it is enabled.
     */
    public function callbackUrl(): ?string
    {
        return ($this->callbackUrlResolver)?->__invoke($this->config);
    }

    /**
     * Verifies the x-jws-signature header and parses the events of a callback.
     *
     * @return list<Event>
     *
     * @throws InvalidCallback When the signature is missing or invalid, or the body is malformed.
     */
    public function parseCallback(string $rawBody, ?string $signature, bool $verifySignature = true): array
    {
        if ($verifySignature) {
            $this->verifier->verify($rawBody, $signature, $this->config->jwksUrl());
        }

        try {
            $payload = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidCallback::malformed('the body is not JSON');
        }

        if (is_array($payload) && ! array_is_list($payload)) {
            $payload = [$payload];
        }

        if (! is_array($payload) || $payload === []) {
            throw InvalidCallback::malformed('the body is not a list of events');
        }

        $events = Event::listFromArray($payload, $this->config->timezone);

        foreach ($events as $event) {
            if ($event->type === '') {
                throw InvalidCallback::malformed('an event has no eventType');
            }
        }

        return $events;
    }

    /**
     * Normalises a document identifier: a bare UUID gets the "DOCUMENT_PROCESS:" prefix.
     */
    public static function documentId(string $documentId): string
    {
        $documentId = trim($documentId);

        if ($documentId === '') {
            throw new InvalidArgumentException('Autenti document identifier must not be empty.');
        }

        return str_contains($documentId, ':') ? $documentId : 'DOCUMENT_PROCESS:' . $documentId;
    }

    private function documentPath(string $documentId): string
    {
        return $this->path(self::documentId($documentId));
    }

    private function path(string $id): string
    {
        $id = trim($id);

        if ($id === '') {
            throw new InvalidArgumentException('Autenti identifier must not be empty.');
        }

        return str_replace(['%3A', '%40'], [':', '@'], rawurlencode($id));
    }

    private function download(string $documentId, string $fileId, ?string $version): Response
    {
        $headers = ['Accept' => '*/*'];

        if ($version !== null) {
            $headers['If-Match'] = $version;
        }

        return $this->call('GET', '/document-processes/' . $this->documentPath($documentId) . '/files/' . $this->path($fileId) . '/content', headers: $headers, retry: true, raw: true);
    }

    private function documentFrom(mixed $payload): DocumentProcess
    {
        if (! is_array($payload) || ! is_string($payload['id'] ?? null)) {
            throw ApiException::unexpectedResponse('the document process has no id');
        }

        return DocumentProcess::fromArray($payload, $this->config->timezone);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function listOfArrays(mixed $payload): array
    {
        if (! is_array($payload) || ! array_is_list($payload)) {
            throw ApiException::unexpectedResponse('a list was expected');
        }

        return array_values(array_filter($payload, is_array(...)));
    }

    /**
     * @param array<string, int|string> $query
     * @param array<string, mixed>|null $json
     * @param array<string, string|list<string>> $headers
     * @param (Closure(PendingRequest): PendingRequest)|null $multipart
     * @return ($raw is true ? Response : mixed)
     */
    private function call(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        array $headers = [],
        ?Closure $multipart = null,
        bool $retry = false,
        bool $raw = false,
    ): mixed {
        $attempts = $retry ? $this->config->retries + 1 : 1;
        $attempt = 0;
        $url = $this->config->apiUrl() . $path . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');

        while (true) {
            $attempt++;

            try {
                $response = $this->transmit($method, $url, $json, $headers, $multipart);

                if ($response->status() === 401) {
                    $this->tokens->forget();
                    $response = $this->transmit($method, $url, $json, $headers, $multipart);
                }
            } catch (ConnectionException $exception) {
                if ($attempt < $attempts) {
                    $this->pause();

                    continue;
                }

                throw ApiException::transport($exception);
            }

            if ($response->serverError() && $attempt < $attempts) {
                $this->pause();

                continue;
            }

            if ($response->successful() && $raw) {
                return $response;
            }

            $body = trim($response->body());
            $payload = null;

            if ($body !== '') {
                try {
                    $payload = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    if ($response->successful()) {
                        throw ApiException::unexpectedResponse('the body is not JSON', $response->status());
                    }
                }
            }

            if ($response->status() === 403) {
                $challenge = Challenge::fromHeader($response->header('X-Challenge'));

                if ($challenge !== null) {
                    throw new ChallengeRequired($challenge, is_array($payload) ? $payload : []);
                }
            }

            if ($response->failed()) {
                $retryAfter = $response->header('Retry-After');

                throw ApiException::fromResponse(is_array($payload) ? $payload : [], $response->status(), is_numeric($retryAfter) ? (int) $retryAfter : null);
            }

            return $payload;
        }
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string|list<string>> $headers
     * @param (Closure(PendingRequest): PendingRequest)|null $multipart
     *
     * @throws ConnectionException
     */
    private function transmit(string $method, string $url, ?array $json, array $headers, ?Closure $multipart): Response
    {
        $request = $this->http
            ->createPendingRequest()
            ->withToken($this->tokens->token())
            ->acceptJson()
            ->timeout($this->config->timeout)
            ->withUserAgent('beziworld-laravel-autenti/1.0')
            ->withHeaders($headers);

        if ($multipart !== null) {
            return $multipart($request)->send($method, $url, ['multipart' => []]);
        }

        if ($json !== null) {
            return $request->withBody(json_encode($json === [] ? new \stdClass() : $json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'application/json')->send($method, $url);
        }

        return $request->send($method, $url);
    }

    private function pause(): void
    {
        if ($this->config->retryDelay > 0) {
            Sleep::usleep($this->config->retryDelay * 1000);
        }
    }

    private static function guessMimeType(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'txt' => 'text/plain',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
    }
}
