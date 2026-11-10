<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Enums;

/**
 * Status filter of the document process list; EVERYTHING combines drafts and sent documents (maximum offset 256).
 */
enum ListStatus: string
{
    case Draft = 'DRAFT';
    case DocumentProcess = 'DOCUMENT_PROCESS';
    case Everything = 'EVERYTHING';
    case Completed = 'COMPLETED';
    case Rejected = 'REJECTED';
    case Withdrawn = 'WITHDRAWN';
    case Processing = 'PROCESSING';
}
