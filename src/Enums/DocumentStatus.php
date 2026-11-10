<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Enums;

/**
 * Lifecycle status of an Autenti document process.
 */
enum DocumentStatus: string
{
    case Unknown = 'UNKNOWN_DOCUMENT_STATUS';
    case Draft = 'DRAFT';
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case Rejected = 'REJECTED';
    case Withdrawn = 'WITHDRAWN';
    case Error = 'ERROR';

    /**
     * The process ended: all parties acted, somebody rejected or the sender withdrew it.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Rejected, self::Withdrawn], true);
    }
}
