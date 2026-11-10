<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Enums;

/**
 * Role of a party in a document process.
 */
enum ParticipantRole: string
{
    case Signer = 'SIGNER';
    case Sender = 'SENDER';
    case Approver = 'APPROVER';
    case Reviewer = 'REVIEWER';
    case Viewer = 'VIEWER';
    case Unspecified = 'UNSPECIFIED_DOCUMENT_PARTY_ROLE';
}
