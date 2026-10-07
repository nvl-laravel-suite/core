<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Foundation\Testing\RefreshDatabase;

/** Exercises callbacks with Laravel's actual RefreshDatabase transaction setup. */
class RefreshDatabaseCommitTestCase extends EarlyCommitCallbacksTestCase
{
    use RefreshDatabase;
}
