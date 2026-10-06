<?php

declare(strict_types=1);

namespace Nvl\Support\Exceptions;

use Exception;
use Nvl\Support\Contracts\PackageException;

/**
 * Base exception for failures raised by shared NVL support primitives.
 *
 * @api
 */
class SupportException extends Exception implements PackageException {}
