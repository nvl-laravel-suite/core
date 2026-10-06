<?php

declare(strict_types=1);

namespace Nvl\Support\Testing;

use Closure;
use Throwable;

/**
 * Records calls and consumes explicit per-method FIFO scripts owned by each fake instance.
 *
 * @api
 */
trait FakeCalls
{
    /** @var array<string, list<array{kind: 'return', value: mixed}|array{kind: 'throw', exception: Throwable}>> */
    private array $fakeScripts = [];

    /** @var list<FakeCall> */
    private array $fakeCallHistory = [];

    /**
     * Queue one result value without executing closures supplied as values.
     */
    public function willReturn(string $method, mixed $value): static
    {
        $this->assertFakeMethod($method);
        $this->fakeScripts[$method][] = ['kind' => 'return', 'value' => $value];

        return $this;
    }

    /**
     * Queue the exact exception to throw on the next matching invocation.
     */
    public function willThrow(string $method, Throwable $exception): static
    {
        $this->assertFakeMethod($method);
        $this->fakeScripts[$method][] = ['kind' => 'throw', 'exception' => $exception];

        return $this;
    }

    /**
     * Return recorded attempts in invocation order, optionally filtered by method.
     *
     * @return list<FakeCall>
     */
    public function calls(?string $method = null): array
    {
        if ($method === null) {
            return $this->fakeCallHistory;
        }

        $this->assertFakeMethod($method);

        return array_values(array_filter(
            $this->fakeCallHistory,
            static fn (FakeCall $call): bool => $call->method === $method,
        ));
    }

    /**
     * Require exactly the requested number of calls matching the method and predicate.
     *
     * @param  Closure(FakeCall): bool|null  $predicate
     */
    public function assertCalled(string $method, ?Closure $predicate = null, int $times = 1): void
    {
        if ($times < 0) {
            throw new FakeExpectationFailed('Fake call expectations require a non-negative count.');
        }

        $matches = 0;
        foreach ($this->calls($method) as $call) {
            if ($predicate === null || $predicate($call)) {
                $matches++;
            }
        }

        if ($matches !== $times) {
            throw new FakeExpectationFailed(
                static::class.'::'.$method.' expected '.$times.' matching calls; recorded '.$matches.'.',
            );
        }
    }

    /**
     * Record an attempt before consuming its next result or exception.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @internal
     */
    protected function invoke(string $method, array $arguments): mixed
    {
        $this->assertFakeMethod($method);
        $this->fakeCallHistory[] = new FakeCall($method, $arguments);

        $script = isset($this->fakeScripts[$method])
            ? array_shift($this->fakeScripts[$method])
            : null;

        if ($script === null) {
            throw new UnscriptedFakeCall(static::class.'::'.$method.' has no remaining scripted response.');
        }

        if ($script['kind'] === 'throw') {
            throw $script['exception'];
        }

        return $script['value'];
    }

    /**
     * Declare only the native methods supported by this fake.
     *
     * @return list<string>
     *
     * @internal
     */
    abstract protected function fakeMethods(): array;

    /**
     * Reject unsupported method names before scripts or expectations are recorded.
     */
    private function assertFakeMethod(string $method): void
    {
        if (! in_array($method, $this->fakeMethods(), true)) {
            throw new FakeExpectationFailed(static::class.' does not support fake method ['.$method.'].');
        }
    }
}
