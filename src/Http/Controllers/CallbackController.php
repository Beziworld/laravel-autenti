<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Http\Controllers;

use Beziworld\Autenti\AutentiManager;
use Beziworld\Autenti\Data\Event;
use Beziworld\Autenti\Events\ApprovalConsented;
use Beziworld\Autenti\Events\AutentiEventReceived;
use Beziworld\Autenti\Events\DocumentCompleted;
use Beziworld\Autenti\Events\DocumentExpired;
use Beziworld\Autenti\Events\DocumentRejected;
use Beziworld\Autenti\Events\DocumentWithdrawn;
use Beziworld\Autenti\Events\ReviewCompleted;
use Beziworld\Autenti\Events\SigningCompleted;
use Beziworld\Autenti\Events\SigningHandedOver;
use Beziworld\Autenti\Exceptions\InvalidCallback;
use Beziworld\Autenti\Exceptions\InvalidConfiguration;
use Beziworld\Autenti\Support\CallbackVerifier;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;

/**
 * Receives Autenti callbacks for the connection named in the URL.
 *
 * The x-jws-signature header is verified with the public keys of the
 * environment of the connection; forged callbacks get HTTP 403 and
 * malformed ones HTTP 400. Authentic callbacks are acknowledged with
 * HTTP 200 after the events are dispatched; an exception in a listener
 * results in HTTP 500 and a redelivery.
 */
final class CallbackController
{
    public function __construct(
        private readonly AutentiManager $manager,
        private readonly Config $settings,
        private readonly Dispatcher $events,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request, ?string $connection = null): Response
    {
        $name = $connection ?? $this->manager->getDefaultConnection();

        try {
            $events = $this->manager->connection($name)->parseCallback(
                $request->getContent(),
                $request->headers->get(CallbackVerifier::HEADER),
                (bool) $this->settings->get('autenti.callbacks.verify_signature', true),
            );
        } catch (InvalidCallback $exception) {
            $this->logger->warning('Rejected Autenti callback: ' . $exception->getMessage(), ['connection' => $name, 'ip' => $request->ip()]);

            return new Response('', $exception->isAuthenticationFailure() ? 403 : 400);
        } catch (InvalidConfiguration $exception) {
            $this->logger->warning('Rejected Autenti callback: ' . $exception->getMessage(), ['connection' => $name, 'ip' => $request->ip()]);

            return new Response('', 403);
        }

        foreach ($events as $event) {
            $this->events->dispatch(new AutentiEventReceived($event, $name));

            $specific = match ($event->type) {
                Event::DOCUMENT_PROCESS_COMPLETED => new DocumentCompleted($event, $name),
                Event::DOCUMENT_PROCESS_REJECTED => new DocumentRejected($event, $name),
                Event::DOCUMENT_PROCESS_WITHDRAWN => new DocumentWithdrawn($event, $name),
                Event::DOCUMENT_PROCESS_EXPIRED => new DocumentExpired($event, $name),
                Event::SIGNING_PROCESS_COMPLETED => new SigningCompleted($event, $name),
                Event::APPROVAL_PROCESS_CONSENTED => new ApprovalConsented($event, $name),
                Event::REVIEW_PROCESS_COMPLETED => new ReviewCompleted($event, $name),
                Event::DOCUMENT_SIGNING_HANDED_OVER => new SigningHandedOver($event, $name),
                default => null,
            };

            if ($specific !== null) {
                $this->events->dispatch($specific);
            }
        }

        return new Response('', 200);
    }
}
