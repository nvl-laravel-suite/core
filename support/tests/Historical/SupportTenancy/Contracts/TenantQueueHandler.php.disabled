<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Contracts;

use Illuminate\Contracts\Queue\Job;

/**
 * Admits tenant metadata before restoring native execution or terminal failure commands.
 *
 * @api
 */
interface TenantQueueHandler
{
    /**
     * Validate captured metadata and inert command ownership without restoring user objects.
     *
     * @param  array<string, mixed>  $data
     */
    public function validate(array $data): void;

    /**
     * Enforce the captured tenant boundary before restoring and executing a command.
     *
     * @param  array<string, mixed>  $data
     */
    public function call(Job $job, array $data): void;

    /**
     * Enforce the captured tenant boundary before restoring a terminal failure command.
     *
     * @param  array<string, mixed>  $data
     */
    public function failed(array $data, mixed $e, string $uuid, ?Job $job = null): void;
}
