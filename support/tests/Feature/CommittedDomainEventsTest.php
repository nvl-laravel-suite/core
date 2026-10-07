<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Nvl\Support\Events\ConnectionCommitCallbacks;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Exceptions\EventCommitRegistrationException;
use Nvl\Support\Tests\Fixtures\C4NativeConnectionFact;

/** Native SQLite connections share the actual manager, without harness transactions. */
function c4Connection(string $name, DatabaseTransactionsManager $transactions): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['name' => $name]);
    $connection->setTransactionManager($transactions);

    return $connection;
}

it('publishes on the source outer commit while a later foreign transaction remains open', function (): void {
    $transactions = new DatabaseTransactionsManager;
    $source = c4Connection('source', $transactions);
    $foreign = c4Connection('foreign', $transactions);
    $native = new Dispatcher(new Container);
    $received = [];
    $native->listen(C4NativeConnectionFact::class, function (C4NativeConnectionFact $event) use (&$received): void {
        $received[] = $event->days;
    });
    $events = new DomainEventDispatcher(static fn (): Dispatcher => $native, new ConnectionCommitCallbacks(static fn (): DatabaseTransactionsManager => $transactions));
    $source->beginTransaction();
    $foreign->beginTransaction();
    $source->beginTransaction();
    $events->dispatch(new C4NativeConnectionFact(30, true), $source);
    $source->commit();
    expect($received)->toBe([]);
    $source->commit();
    expect($received)->toBe([30])->and($foreign->transactionLevel())->toBe(1);
    $foreign->rollBack();
    expect($received)->toBe([30]);
});

it('discards rolled back savepoint callbacks and previously committed inner callbacks on outer rollback', function (): void {
    $transactions = new DatabaseTransactionsManager;
    $source = c4Connection('source', $transactions);
    $commits = new ConnectionCommitCallbacks(static fn (): DatabaseTransactionsManager => $transactions);
    $received = [];
    $source->beginTransaction();
    $source->beginTransaction();
    $commits->afterCommit($source, function () use (&$received): void {
        $received[] = 'discarded';
    });
    $source->rollBack();
    $commits->afterCommit($source, function () use (&$received): void {
        $received[] = 'outer';
    });
    $source->commit();
    expect($received)->toBe(['outer']);
    $source->beginTransaction();
    $source->beginTransaction();
    $commits->afterCommit($source, function () use (&$received): void {
        $received[] = 'inner';
    });
    $source->commit();
    $source->rollBack();
    expect($received)->toBe(['outer']);
});

it('executes immediately only outside the source transaction and fails closed on missing records', function (): void {
    $transactions = new DatabaseTransactionsManager;
    $source = c4Connection('source', $transactions);
    $wrongManager = new ConnectionCommitCallbacks(static fn (): DatabaseTransactionsManager => new DatabaseTransactionsManager);
    $count = 0;
    $wrongManager->afterCommit($source, function () use (&$count): void {
        $count++;
    });
    $source->beginTransaction();
    expect(fn () => $wrongManager->afterCommit($source, function () use (&$count): void {
        $count++;
    }))
        ->toThrow(EventCommitRegistrationException::class);
    $source->rollBack();
    expect($count)->toBe(1);
});

it('keeps only callbacks from the final successful native transaction retry', function (): void {
    $transactions = new DatabaseTransactionsManager;
    $source = c4Connection('source', $transactions);
    $commits = new ConnectionCommitCallbacks(static fn (): DatabaseTransactionsManager => $transactions);
    $attempts = 0;
    $received = [];
    $source->transaction(function () use ($source, $commits, &$attempts, &$received): void {
        $attempt = ++$attempts;
        $commits->afterCommit($source, function () use ($attempt, &$received): void {
            $received[] = $attempt;
        });
        if ($attempt === 1) {
            throw new PDOException('Deadlock found when trying to get lock');
        }
    }, 2);
    expect($attempts)->toBe(2)->and($received)->toBe([2]);
});

it('preserves registration order across source savepoints before publishing facts', function (): void {
    $transactions = new DatabaseTransactionsManager;
    $source = c4Connection('ordered-source', $transactions);
    $commits = new ConnectionCommitCallbacks(static fn (): DatabaseTransactionsManager => $transactions);
    $received = [];
    $source->beginTransaction();
    $commits->afterCommit($source, function () use (&$received): void {
        $received[] = 30;
    });
    $source->beginTransaction();
    $commits->afterCommit($source, function () use (&$received): void {
        $received[] = 60;
    });
    $source->commit();
    $commits->afterCommit($source, function () use (&$received): void {
        $received[] = 90;
    });
    $source->commit();
    expect($received)->toBe([30, 60, 90]);
});

it('resolves compatible manual event delivery against the current host binding', function (): void {
    $original = Container::getInstance();
    $host = new Container;
    $transactions = new DatabaseTransactionsManager;
    $callbacks = new ConnectionCommitCallbacks(static fn (): DatabaseTransactionsManager => $transactions);
    $native = new Dispatcher($host);
    $first = new DomainEventDispatcher(static fn (): Dispatcher => $native, $callbacks);
    $second = new DomainEventDispatcher(static fn (): Dispatcher => $native, $callbacks);
    try {
        Container::setInstance($host);
        $host->instance(DomainEventDispatcher::class, $first);
        expect(DomainEventDispatcher::current())->toBe($first);
        $host->instance(DomainEventDispatcher::class, $second);
        expect(DomainEventDispatcher::current())->toBe($second);
    } finally {
        Container::setInstance($original);
    }
});
