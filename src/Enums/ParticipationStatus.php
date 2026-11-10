<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Enums;

/**
 * State of the participation of a party in a document process.
 */
enum ParticipationStatus: string
{
    case None = 'NONE';
    case Pending = 'PENDING';
    case Completed = 'COMPLETED';
    case Obsolete = 'OBSOLETE';
    case Failed = 'FAILED';
}
