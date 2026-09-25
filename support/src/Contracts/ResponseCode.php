<?php

declare(strict_types=1);

namespace Nvl\Support\Contracts;

use BackedEnum;

/**
 * Marker contract for backed enums used as stable machine-readable response codes.
 */
interface ResponseCode extends BackedEnum {}
