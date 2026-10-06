<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Exceptions;

use Nvl\Support\Exceptions\BusinessException;

/**
 * Base transport-neutral failure for tenancy configuration and isolation.
 */
class TenancyException extends BusinessException {}
