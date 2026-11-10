<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Enums;

/**
 * Type of electronic signature requested from a signer; BASIC (Electronic Signature by Autenti) is the default.
 */
enum SignatureType: string
{
    case Basic = 'SIGNATURE_PROVIDER-SIGNATURE_TYPE:BASIC';
    case Advanced = 'SIGNATURE_PROVIDER-SIGNATURE_TYPE:ADVANCED';
    case Qualified = 'SIGNATURE_PROVIDER-SIGNATURE_TYPE:QUALIFIED';
}
