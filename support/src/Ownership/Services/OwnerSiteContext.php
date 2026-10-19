<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Container\Container;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\VerifiedOwnerSiteContext;

/**
 * Reads only the admitted public context, never request attributes or mutable configuration.
 *
 * @api
 */
final class OwnerSiteContext
{
    /** Return the currently admitted public site, if any. */
    public static function current(): ?VerifiedOwnerSiteContext
    {
        return Container::getInstance()->make(OwnershipContext::class)->snapshot()->publicSite;
    }

    /** Require the admitted site before public identity, URL or cache work. */
    public static function require(): VerifiedOwnerSiteContext
    {
        return self::current() ?? throw new OwnershipViolation('A verified owner site is required.');
    }

    private function __construct() {}
}
