<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Contracts;

/** Validates inert native batch options before restoring callback objects. @internal */
interface OwnershipBatchOptions
{
    /** Inspect serialized bytes without executing native callbacks. */
    public function validate(string $serialized): void;
}
