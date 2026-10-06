<?php

declare(strict_types=1);

namespace Nvl\Support\Testing;

use Nvl\Support\Contracts\PackageException;
use RuntimeException;

/**
 * Reports an invalid fake script or an unmet call expectation without a testing framework.
 *
 * @api
 */
final class FakeExpectationFailed extends RuntimeException implements PackageException {}
