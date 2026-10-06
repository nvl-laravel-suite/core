<?php

declare(strict_types=1);

namespace Nvl\Support\Testing;

use Nvl\Support\Contracts\PackageException;
use RuntimeException;

/**
 * Reports an attempted fake invocation with no remaining scripted response.
 *
 * @api
 */
final class UnscriptedFakeCall extends RuntimeException implements PackageException {}
