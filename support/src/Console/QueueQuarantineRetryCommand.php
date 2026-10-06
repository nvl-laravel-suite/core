<?php

declare(strict_types=1);

namespace Nvl\Support\Console;

use Illuminate\Console\Command;
use Nvl\Support\Tenancy\Services\TenantQueueQuarantine;
use Throwable;

/** Requeues captured NVL work through raw transport without restoring the original command. */
final class QueueQuarantineRetryCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:queue:retry {id* : Native failed-job IDs identifying NVL quarantine records}';

    /** @var string */
    protected $description = 'Retry quarantined NVL queue envelopes without restoring captured commands';

    /** Retry the selected native records and leave rejected or unsupported records available for inspection. */
    public function handle(TenantQueueQuarantine $quarantine): int
    {
        foreach ($this->argument('id') as $id) {
            try {
                $quarantine->retry($id);
                $this->info("Requeued raw NVL payload [{$id}].");
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
