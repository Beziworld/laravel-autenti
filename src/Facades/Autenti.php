<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Facades;

use Beziworld\Autenti\AutentiManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Beziworld\Autenti\AutentiClient connection(?string $name = null)
 * @method static \Beziworld\Autenti\AutentiClient using(\Beziworld\Autenti\AutentiConfig|array<string, mixed> $config)
 * @method static \Beziworld\Autenti\AutentiClient asUser(string $email, ?string $userId = null, ?string $connection = null)
 * @method static void resolveConnectionsUsing(\Closure(string): (\Beziworld\Autenti\AutentiConfig|array<string, mixed>|null) $resolver)
 * @method static string getDefaultConnection()
 * @method static list<string> connectionNames()
 * @method static void purge(?string $name = null)
 * @method static \Beziworld\Autenti\AutentiConfig config()
 * @method static ?string connectionName()
 * @method static string token()
 * @method static void forgetToken()
 * @method static list<\Beziworld\Autenti\Data\DocumentProcess> documents(?\Beziworld\Autenti\Data\DocumentFilter $filter = null)
 * @method static \Beziworld\Autenti\Data\DocumentProcess document(string $documentId)
 * @method static \Beziworld\Autenti\Data\DocumentProcess createDocument(\Beziworld\Autenti\Data\DocumentRequest $request)
 * @method static \Beziworld\Autenti\Data\DocumentProcess updateDocument(string $documentId, ?string $title = null, ?string $description = null, ?string $language = null)
 * @method static \Beziworld\Autenti\Data\DocumentProcess sendDocument(\Beziworld\Autenti\Data\DocumentRequest $request, array<string, string|null> $files)
 * @method static list<\Beziworld\Autenti\Data\Participant> participants(string $documentId)
 * @method static \Beziworld\Autenti\Data\Participant addParticipant(string $documentId, \Beziworld\Autenti\Data\ParticipantRequest $participant)
 * @method static void removeParticipant(string $documentId, string $partyId)
 * @method static list<\Beziworld\Autenti\Data\FileInfo> files(string $documentId)
 * @method static \Beziworld\Autenti\Data\FileInfo file(string $documentId, string $fileId)
 * @method static \Beziworld\Autenti\Data\FileInfo addFile(string $documentId, string $contents, string $filename, ?string $mimeType = null, \Beziworld\Autenti\Enums\FilePurpose $purpose = \Beziworld\Autenti\Enums\FilePurpose::Source)
 * @method static \Beziworld\Autenti\Data\FileInfo addFileFromPath(string $documentId, string $path, ?string $filename = null, ?string $mimeType = null)
 * @method static void removeFile(string $documentId, string $fileId)
 * @method static string fileContent(string $documentId, string $fileId, ?string $version = null)
 * @method static \Beziworld\Autenti\Data\DownloadedFile downloadFile(string $documentId, \Beziworld\Autenti\Data\FileInfo|string $file, ?string $version = null)
 * @method static \Beziworld\Autenti\Data\DownloadedFile|null downloadSignedFile(string $documentId)
 * @method static list<\Beziworld\Autenti\Data\ActionOption> availableActions(string $documentId)
 * @method static list<\Beziworld\Autenti\Data\Event> performAction(string $documentId, string $action, list<array<string, mixed>> $assertions = [])
 * @method static list<\Beziworld\Autenti\Data\Event> send(string $documentId)
 * @method static list<\Beziworld\Autenti\Data\Event> withdraw(string $documentId)
 * @method static list<\Beziworld\Autenti\Data\Event> remind(string $documentId, ?list<string> $partyIds = null)
 * @method static list<\Beziworld\Autenti\Data\Event> history(string $documentId)
 * @method static list<\Beziworld\Autenti\Data\ActionLink> actionLinks(string $documentId, string $partyId, ?string $redirectUrl = null)
 * @method static ?string signingLink(string $documentId, string $partyId, ?string $redirectUrl = null)
 * @method static list<\Beziworld\Autenti\Data\CallbackDefinition> callbacks()
 * @method static \Beziworld\Autenti\Data\CallbackDefinition callback(string $callbackId)
 * @method static \Beziworld\Autenti\Data\CallbackDefinition registerCallback(?string $url = null)
 * @method static void deleteCallback(string $callbackId)
 * @method static ?string callbackUrl()
 * @method static list<\Beziworld\Autenti\Data\Event> parseCallback(string $rawBody, ?string $signature, bool $verifySignature = true)
 *
 * @see \Beziworld\Autenti\AutentiManager
 * @see \Beziworld\Autenti\AutentiClient
 */
final class Autenti extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AutentiManager::class;
    }
}
