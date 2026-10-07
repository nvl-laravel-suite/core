<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Database\DatabaseTransactionsManager;
use Nvl\Support\Events\ConnectionCommitCallbacks;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Tests\TestCase;

/** Resolves the singleton before Laravel installs its testing transaction manager. */
abstract class EarlyCommitCallbacksTestCase extends TestCase
{
    public ConnectionCommitCallbacks $earlyCallbacks;

    public DatabaseTransactionsManager $earlyTransactions;

    public DomainEventDispatcher $earlyEvents;

    /** @return array<class-string, class-string> Initialized native test traits. */
    protected function setUpTraits(): array
    {
        $this->earlyCallbacks = $this->app->make(ConnectionCommitCallbacks::class);
        $this->earlyTransactions = $this->app->make('db.transactions');
        $this->earlyEvents = $this->app->make(DomainEventDispatcher::class);

        return parent::setUpTraits();
    }
}
