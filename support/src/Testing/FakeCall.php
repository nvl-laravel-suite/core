<?php

declare(strict_types=1);

namespace Nvl\Support\Testing;

/**
 * Retains one fake invocation and its named arguments without serializing object handles.
 *
 * @api
 */
final readonly class FakeCall
{
    /**
     * Capture the method and arguments observed at invocation.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(public string $method, public array $arguments) {}
}
