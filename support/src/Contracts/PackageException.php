<?php

declare(strict_types=1);

namespace Nvl\Support\Contracts;

use Throwable;

/**
 * Identifies package failures without imposing transport or response behavior.
 *
 * @api
 */
interface PackageException extends Throwable {}
