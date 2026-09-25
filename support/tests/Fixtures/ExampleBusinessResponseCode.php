<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Nvl\Support\Contracts\ResponseCode;

/**
 * Provides a stable response code for Support exception contract tests.
 */
enum ExampleBusinessResponseCode: string implements ResponseCode
{
    case Conflict = 'conflict';
}
