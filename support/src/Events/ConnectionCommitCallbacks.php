<?php

declare(strict_types=1);

namespace Nvl\Support\Events;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Nvl\Support\Exceptions\EventCommitRegistrationException;

/** Attaches callbacks to the exact native source transaction without replacing host infrastructure.
 *
 * @api
 */
final readonly class ConnectionCommitCallbacks
{
    /**
     * Resolve the current host manager after test or application rebinding.
     *
     * @param  Closure(): DatabaseTransactionsManager  $transactions
     */
    public function __construct(private Closure $transactions) {}

    /**
     * Execute after the source outer commit, or immediately outside a source transaction.
     *
     * @param  Closure(): void  $callback
     *
     * @throws EventCommitRegistrationException
     */
    public function afterCommit(Connection $connection, Closure $callback): void
    {
        $level = $connection->transactionLevel();
        if ($level === 0) {
            $callback();

            return;
        }

        $transactions = ($this->transactions)();
        $record = $transactions->getPendingTransactions()->last(
            static fn (DatabaseTransactionRecord $record): bool => $record->connection === $connection->getName()
                && $record->level === $level,
        );
        if (! $record instanceof DatabaseTransactionRecord) {
            throw new EventCommitRegistrationException;
        }

        $applicable = $transactions->callbackApplicableTransactions()->filter(
            static fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $connection->getName(),
        );
        if (! $applicable->contains($record)) {
            $callback();

            return;
        }

        $root = $applicable->sortBy('level')->first();
        if (! $root instanceof DatabaseTransactionRecord || $root === $record) {
            $record->addCallback($callback);

            return;
        }

        $committed = false;
        $record->addCallback(static function () use (&$committed): void {
            $committed = true;
        });
        $root->addCallback(static function () use (&$committed, $callback): void {
            if ($committed) {
                $callback();
            }
        });
    }
}
