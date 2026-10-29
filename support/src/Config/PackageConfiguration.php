<?php

declare(strict_types=1);

namespace Nvl\Support\Config;

/** Resolves canonical NVL configuration roots. */
final class PackageConfiguration
{
    /** Return a canonical configuration root for a logical package or existing root. */
    public static function key(string $package): string
    {
        return str_starts_with($package, 'nvl-') ? $package : 'nvl-'.$package;
    }

    /** Return a logical package identifier without changing domain resource identifiers. */
    public static function logical(string $package): string
    {
        return str_starts_with($package, 'nvl-') ? substr($package, 4) : $package;
    }

    private function __construct() {}
}
