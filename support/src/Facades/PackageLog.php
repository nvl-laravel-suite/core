<?php

declare(strict_types=1);

namespace Nvl\Support\Facades;

use Illuminate\Support\Facades\Facade;
use Nvl\Support\Logging\PackageLogger;

/** Stateless package diagnostic entry point for transport and queued execution seams.
 *
 * @method static void log(string $package, string $level, string $key, array<string, mixed> $context = [], string $verbosity = 'normal')
 *
 * @see PackageLogger
 *
 * @api
 */
final class PackageLog extends Facade
{
    /** Resolve the container-owned package logger. */
    protected static function getFacadeAccessor(): string
    {
        return PackageLogger::class;
    }
}
