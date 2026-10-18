<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Enums;

/**
 * Distinguishes absent isolation, missing admission, an owner, and explicit central work.
 *
 * @api
 */
enum OwnershipContextMode: string
{
    case Disabled = 'disabled';
    case Unresolved = 'unresolved';
    case Owner = 'owner';
    case Central = 'central';
}
