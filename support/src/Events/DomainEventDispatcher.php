<?php

declare(strict_types=1);

namespace Nvl\Support\Events;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Nvl\Support\Contracts\DomainEvent;

/** Publishes one captured domain fact through the host dispatcher after its source commits.
 *
 * @api
 */
final readonly class DomainEventDispatcher
{
    /** Retain the host event dispatcher and exact source-commit scheduler. */
    public function __construct(private Dispatcher $events, private ConnectionCommitCallbacks $commits) {}

    /** Publish the immutable event after the supplied writer connection commits. */
    public function dispatch(DomainEvent $event, Connection $connection): void
    {
        $this->commits->afterCommit($connection, function () use ($event): void {
            $this->events->dispatch($event);
        });
    }
}
