<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Container\Container;
use Illuminate\Queue\Queue;
use Nvl\Support\Ownership\Compatibility\LegacyQueueAuthority;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;

/** Supplies disabled scalar capture for opted-in native commands without requiring a runtime. @internal */
abstract class OwnershipQueuePublication extends Queue
{
    /** Preserve host callbacks and avoid capturing one application in process-wide state. */
    public static function register(): void
    {
        $callback = [self::class, 'payload'];
        if (! in_array($callback, self::$createPayloadCallbacks, true)) {
            self::createPayloadUsing($callback);
        }
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function payload(?string $connection = null, ?string $queue = null, array $payload = []): array
    {
        $app = Container::getInstance();
        if ($app->make(LegacyQueueAuthority::class)->active()) {
            return [];
        }
        if ($app->bound('nvl.ownership.runtime') && $app->make('config')->get('nvl-ownership.enabled') === true) {
            return [];
        }
        $data = $payload['data'] ?? null;
        $command = is_array($data) ? ($data['command'] ?? null) : null;
        if (! is_object($command) || ! $app->make(OwnershipQueueCarrier::class)->captured($command)) {
            return [];
        }
        $envelope = $app->make(OwnershipQueueCarrier::class)->envelope($command);
        $app->make(OwnershipQueueContext::class)->run($envelope, static fn (): null => null);

        return ['data' => [...$data, 'nvl_ownership' => $envelope->toArray()]];
    }
}
