<?php

declare(strict_types=1);

namespace Nvl\Support\Events;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as LaravelDispatcher;
use InvalidArgumentException;

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
            $legacyListeners = $listeners[$legacy] ?? [];
            $canonicalListeners = $listeners[$canonical] ?? [];
            if (! is_array($legacyListeners) || ! is_array($canonicalListeners)) {
                throw new InvalidArgumentException('Raw event registrations must be listener lists.');
            }
            foreach ($legacyListeners as $listener) {
                if (in_array($listener, $canonicalListeners, true)) {
                    continue;
                }
                if ($events->makeListener(self::nativeListener($listener))($canonical, [$event]) === false) {
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
        $native = is_array($listener) || is_string($listener) || $listener instanceof Closure
            ? $listener : Closure::fromCallable($listener);
        $this->events->listen($this->canonicalName($event), $native);
    }

    /** Preserve native class listener resolution and adapt other declared PHP callables. */
    private static function nativeListener(mixed $listener): Closure|string
    {
        if (is_string($listener) || $listener instanceof Closure) {
            return $listener;
        }
        if (is_array($listener) && array_is_list($listener) && count($listener) === 2
            && is_string($listener[0]) && is_string($listener[1])) {
            return $listener[0].'@'.$listener[1];
        }
        if (is_callable($listener)) {
            return Closure::fromCallable($listener);
        }

        throw new InvalidArgumentException('Event listeners require a native class listener or PHP callable.');
    }
}
