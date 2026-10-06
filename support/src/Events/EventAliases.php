<?php

declare(strict_types=1);

namespace Nvl\Support\Events;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as LaravelDispatcher;

/** Bridges deprecated exact event registrations without redispatching or replacing the host dispatcher.
 *
 * @api
 */
final class EventAliases
{
    /** @var array<class-string, class-string> */
    private array $aliases = [];

    /** Retain the application's existing dispatcher. */
    public function __construct(private readonly Dispatcher $events) {}

    /**
     * Register one canonical event and its deprecated PHP class alias.
     *
     * @param  class-string  $canonical
     * @param  class-string  $legacy
     */
    public function register(string $canonical, string $legacy): void
    {
        if (isset($this->aliases[$legacy])) {
            return;
        }

        $this->aliases[$legacy] = $canonical;
        class_exists($legacy);
        if (! $this->events instanceof LaravelDispatcher) {
            return;
        }

        $events = $this->events;
        $events->listen($canonical, static function (object $event) use ($events, $canonical, $legacy): ?bool {
            $listeners = $events->getRawListeners();
            foreach ($listeners[$legacy] ?? [] as $listener) {
                if (in_array($listener, $listeners[$canonical] ?? [], true)) {
                    continue;
                }
                if ($events->makeListener($listener)($canonical, [$event]) === false) {
                    return false;
                }
            }

            return null;
        });
    }

    /** Return the canonical registration name or the original unrelated event name. */
    public function canonicalName(string $event): string
    {
        return $this->aliases[$event] ?? $event;
    }

    /**
     * Register explicitly through the canonical adapter, including with custom dispatchers.
     *
     * @param  callable|array<array-key, mixed>|string  $listener
     */
    public function listen(string $event, callable|array|string $listener): void
    {
        $this->events->listen($this->canonicalName($event), $listener);
    }
}
