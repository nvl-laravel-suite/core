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
    protected $signature = 'nvl:queue:retry {id* : Native failed-job IDs identifying captured NVL records}';

    /** @var string */
    protected $description = 'Retry captured NVL queue envelopes without restoring captured commands';

    /** Retry the selected native records and leave rejected or unsupported records available for inspection. */
    public function handle(TenantQueueQuarantine $quarantine): int
    {
        $ids = self::nativeIds($this->argument('id'));
        if ($ids === null) {
            $this->error('Native failed-job IDs must be non-empty strings.');

            return self::FAILURE;
        }
        foreach ($ids as $id) {
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

    /** @return list<string>|null */
    private static function nativeIds(mixed $value): ?array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return null;
        }
        foreach ($value as $id) {
            if (! is_string($id) || trim($id) === '') {
                return null;
            }
        }

        return $value;
    }
}
