<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Tests\Fixtures\C4NativeConnectionFact;
use Nvl\Support\Tests\Fixtures\DatabaseTransactionsCommitTestCase;

uses(DatabaseTransactionsCommitTestCase::class);

it('honors the rebound DatabaseTransactions manager after early singleton resolution', function (): void {
    expect($this->app->make('db.transactions'))->not->toBe($this->earlyTransactions);
    $connection = $this->app->make('db')->connection();
    $native = $this->app->make(Dispatcher::class);
    $received = [];
    $native->listen(C4NativeConnectionFact::class, static function (C4NativeConnectionFact $event) use (&$received): void {
        $received[] = $event->days;
    });
    $events = new DomainEventDispatcher(static fn (): Dispatcher => $native, $this->earlyCallbacks);

    $connection->beginTransaction();
    $events->dispatch(new C4NativeConnectionFact(30, true), $connection);
    expect($received)->toBe([]);
    $connection->commit();
    expect($received)->toBe([30]);

    $connection->beginTransaction();
    $events->dispatch(new C4NativeConnectionFact(60, true), $connection);
    $connection->rollBack();
    expect($received)->toBe([30]);
});
