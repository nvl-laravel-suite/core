<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Foundation\Testing\DatabaseTransactions;

/** Exercises callbacks with Laravel's actual DatabaseTransactions setup. */
class DatabaseTransactionsCommitTestCase extends EarlyCommitCallbacksTestCase
{
    use DatabaseTransactions;
}
