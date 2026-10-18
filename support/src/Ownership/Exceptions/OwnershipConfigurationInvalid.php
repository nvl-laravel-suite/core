<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Exceptions;

use InvalidArgumentException;

/** Reports invalid immutable ownership or model replacement configuration. */
final class OwnershipConfigurationInvalid extends InvalidArgumentException {}
