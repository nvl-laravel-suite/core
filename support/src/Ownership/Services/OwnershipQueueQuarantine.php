<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Container\Container as Application;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SyncQueue;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Throwable;

/**
 * Stores rejected wire payloads without restoring command or failure callback objects.
 *
 * @internal
 */
final readonly class OwnershipQueueQuarantine
{
    /** Identifies rejection records in the host's native failed-job storage. */
    public const string REJECTION_PREFIX = 'NVL queue envelope rejected: ';

    /** Resolve the host's current native failed-job provider only for rejected NVL work. */
    public function __construct(private Container $container) {}

    /**
     * Record and delete captured work before the worker invokes its native handler.
     *
     * @throws OwnershipViolation When rejecting a synchronous dispatch
     * @throws OwnershipConfigurationInvalid When the failed-job provider is incompatible
     */
    public function reject(JobProcessing $event, Throwable $reason): void
    {
        $exception = new OwnershipViolation(self::REJECTION_PREFIX.$reason->getMessage(), previous: $reason);
        $event->job->markAsFailed();
        try {
            $failer = $this->container->get('queue.failer');
            if (! $failer instanceof FailedJobProviderInterface) {
                throw new OwnershipConfigurationInvalid('NVL queue quarantine requires a native failed-job provider.');
            }
            if ($failer->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $exception) === null) {
                throw new OwnershipConfigurationInvalid('NVL queue quarantine requires persistent native failed-job storage.');
            }
        } finally {
            $event->job->delete();
        }

        if ($event->job instanceof SyncJob) {
            throw $exception;
        }
    }

    /** Detect only native records created by this quarantine boundary. */
    public static function isQuarantined(object $failure): bool
    {
        return is_string($failure->exception ?? null)
            && preg_match('/(?:\\A|\\n\\nNext )'.preg_quote(OwnershipViolation::class.': '.self::REJECTION_PREFIX, '/').'/', $failure->exception) === 1;
    }

    /** Recognize scalar captured wire work without restoring its command or native model. */
    public static function isCaptured(object $failure): bool
    {
        if (! is_string($failure->payload ?? null)) {
            return false;
        }
        $payload = json_decode($failure->payload, true);
        $data = is_array($payload) ? ($payload['data'] ?? null) : null;

        return is_array($data) && (array_key_exists('nvl_ownership', $data) || array_key_exists('nvl_tenancy', $data));
    }

    /**
     * Stop Laravel's eager command restoration unless a non-enforcing worker would itself restore this inert capture.
     *
     * @throws OwnershipViolation When native retry would restore quarantined or authority-bearing work
     */
    public static function beforeNativeRetry(JobRetryRequested $event): void
    {
        $quarantined = self::isQuarantined($event->job);
        if (! $quarantined && ! self::isCaptured($event->job)) {
            return;
        }
        $app = Application::getInstance();
        $reason = null;
        if (! $quarantined && ! ($app->bound('nvl.ownership.runtime') && $app->make('config')->get('nvl-ownership.enabled') === true)) {
            try {
                $data = $event->payload()['data'] ?? null;
                if (! is_array($data) || ! is_array($data['nvl_ownership'] ?? null) || array_key_exists('nvl_tenancy', $data)) {
                    throw new OwnershipViolation('Queued ownership requires its native scalar envelope.');
                }
                $app->make(DisabledOwnershipQueueContext::class)->run(OwnershipJobEnvelope::fromArray($data['nvl_ownership']), static fn (): null => null);

                return;
            } catch (Throwable $reason) {
            }
        }

        throw new OwnershipViolation('Quarantined NVL work requires nvl:queue:retry; native retry restores the captured command.', previous: $reason);
    }

    /**
     * Requeue an identified quarantine record verbatim without renewing captured command metadata.
     *
     * @throws OwnershipViolation When the record or queue cannot support safe raw retry
     * @throws OwnershipConfigurationInvalid When the failed-job provider is incompatible
     */
    public function retry(string $id): void
    {
        $failer = $this->container->get('queue.failer');
        if (! $failer instanceof FailedJobProviderInterface) {
            throw new OwnershipConfigurationInvalid('NVL queue quarantine requires a native failed-job provider.');
        }
        $failure = $failer->find($id);
        if ($failure === null || (! self::isQuarantined($failure) && ! self::isCaptured($failure))) {
            throw new OwnershipViolation('Raw retry requires an identified NVL quarantine record.');
        }
        if (! is_string($failure->payload ?? null) || ! is_string($failure->connection ?? null) || ! is_string($failure->queue ?? null)) {
            throw new OwnershipViolation('The failed-job provider did not return a complete raw queue record.');
        }
        $queue = $this->container->make(Factory::class)->connection($failure->connection);
        if ($queue instanceof SyncQueue || method_exists($queue, 'getQueueableOptions')) {
            throw new OwnershipViolation('This queue requires an explicit raw retry integration; its captured command cannot be restored.');
        }
        $pushed = $queue->pushRaw($failure->payload, $failure->queue);
        if ($pushed === null || $pushed === false) {
            throw new OwnershipViolation('The queue did not confirm raw retry; the failed-job record was retained.');
        }
        $failer->forget($id);
    }
}
