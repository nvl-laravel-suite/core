<?php

declare(strict_types=1);

namespace Nvl\Support\Events;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Nvl\Support\Contracts\DomainEvent;

/** Publishes one captured domain fact through the host dispatcher after its source commits.
 *
 * @api
 */
final readonly class DomainEventDispatcher
{
    /**
     * Resolve the current host dispatcher when the source commit publishes the fact.
     *
     * @param  Closure(): Dispatcher  $events
     */
    public function __construct(private Closure $events, private ConnectionCommitCallbacks $commits) {}

    /** Publish the immutable event after the supplied writer connection commits. */
    public function dispatch(DomainEvent $event, Connection $connection): void
    {
        $this->commits->afterCommit($connection, function () use ($event): void {
            ($this->events)()->dispatch($event);
        });
    }
}
