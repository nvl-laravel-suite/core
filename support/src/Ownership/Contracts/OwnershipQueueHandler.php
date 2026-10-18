<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Contracts;

use Illuminate\Contracts\Queue\Job;

/** Enforces raw admission and both native command-restoration boundaries. @api */
interface OwnershipQueueHandler
{
    /** @param array<string, mixed> $data */
    public function validate(array $data): void;

    /** @param array<string, mixed> $data */
    public function call(Job $job, array $data): void;

    /** @param array<string, mixed> $data */
    public function failed(array $data, mixed $e, string $uuid, ?Job $job = null): void;
}
