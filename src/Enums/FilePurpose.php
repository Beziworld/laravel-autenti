<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Enums;

/**
 * Purpose of a file attached to a document process; uploads currently accept SOURCE_FILE only.
 */
enum FilePurpose: string
{
    case Unspecified = 'UNSPECIFIED_CONTENT_PURPOSE';
    case Source = 'SOURCE_FILE';
    case Attachment = 'ATTACHMENT_FILE';
    case PartiallySigned = 'PARTIALLY_SIGNED_CONTENT_FILE';
    case Signed = 'SIGNED_CONTENT_FILE';
    case Archive = 'CONTENT_ARCHIVE';
    case SignatureCard = 'SIGNATURE_CARD';
}
