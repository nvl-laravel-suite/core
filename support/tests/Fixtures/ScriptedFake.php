<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Nvl\Support\Testing\FakeCalls;

/** Exercises the recorder without a framework application or external collaborator. */
final class ScriptedFake
{
    use FakeCalls;

    /** Return the explicitly queued value for one named input. */
    public function read(string $identity): mixed
    {
        return $this->invoke('read', ['identity' => $identity]);
    }

    /** Consume the explicitly queued write result. */
    public function write(string $identity, mixed $value): void
    {
        $this->invoke('write', ['identity' => $identity, 'value' => $value]);
    }

    /** @return list<string> */
    protected function fakeMethods(): array
    {
        return ['read', 'write'];
    }
}
