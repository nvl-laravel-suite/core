<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Container\Container;
use Illuminate\Queue\Events\JobProcessing;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Throwable;

/**
 * Denies captured authority before command restoration when its optional runtime is absent.
 *
 * @internal
 */
final readonly class UnavailableOwnershipQueueAdmission
{
    /** Resolve only the current worker's absence guard and rejection storage. */
    public function __construct(private Container $container) {}

    /** Admit inert unowned metadata without replacing Laravel's native queue handler. */
    public function handle(JobProcessing $event): void
    {
        if ($this->container->bound('nvl.ownership.runtime')
            && $this->container->make('config')->get('nvl-ownership.enabled') === true) {
            return;
        }
        $data = $event->job->payload()['data'] ?? null;
        if (! is_array($data)) {
            return;
        }
        try {
            $name = $data['commandName'] ?? null;
            if (! array_key_exists('nvl_ownership', $data) && ! array_key_exists('nvl_tenancy', $data)
                && (! is_string($name) || ! is_subclass_of($name, OwnershipQueuedJob::class))) {
                return;
            }
            if (! is_array($data['nvl_ownership'] ?? null) || array_key_exists('nvl_tenancy', $data)) {
                throw new OwnershipViolation('Queued ownership requires its native scalar envelope.');
            }
            $this->container->make(DisabledOwnershipQueueContext::class)->run(
                OwnershipJobEnvelope::fromArray($data['nvl_ownership']),
                static fn (): null => null,
            );
        } catch (Throwable $exception) {
            $this->container->make(OwnershipQueueQuarantine::class)->reject($event, $exception);
        }
    }
}
