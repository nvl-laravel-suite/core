<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/** Fixed adapters for native commands and native delivery wrappers. @internal */
final class OwnershipQueueCarrier
{
    /** Detect only explicit carriers and exact native delivery wrappers. */
    public function captured(object $command): bool
    {
        if ($command instanceof OwnershipQueuedJob) {
            return true;
        }
        foreach ($this->carriers($command) as $carrier) {
            if ($carrier instanceof OwnershipQueuedJob) {
                return true;
            }
        }

        return false;
    }

    /** Read explicit producer capture without adopting the ambient serialization scope. */
    public function envelope(object $command): OwnershipJobEnvelope
    {
        if ($command instanceof OwnershipQueuedJob) {
            return $command->ownershipJobEnvelope();
        }
        $carriers = $this->carriers($command);
        $envelope = null;
        foreach ($carriers as $carrier) {
            if ($carrier instanceof OwnershipQueuedJob) {
                $next = $carrier->ownershipJobEnvelope();
                if ($envelope !== null && $envelope->toArray() !== $next->toArray()) {
                    throw new OwnershipViolation('Native wrapper event arguments have different captured ownerships.');
                }
                $envelope = $next;
            } elseif (is_object($carrier)) {
                throw new OwnershipViolation('Native wrapper objects require explicit ownership capture.');
            }
        }

        return $envelope ?? throw new OwnershipViolation('Enabled ownership jobs must carry an explicitly captured OwnershipJobEnvelope.');
    }

    /** @return array<array-key, mixed> */
    private function carriers(object $command): array
    {
        return match ($command::class) {
            SendQueuedMailable::class => [$command->mailable],
            SendQueuedNotifications::class => [$command->notification],
            CallQueuedListener::class => $this->listenerArguments($command->data),
            default => [],
        };
    }

    /** @return array<array-key, mixed> */
    private function listenerArguments(mixed $data): array
    {
        if (! is_array($data)) {
            throw new OwnershipViolation('Native queued listeners require explicit captured event arguments.');
        }

        return $data;
    }

    /** Recognize only the exact installed native wrapper classes. */
    public function nativeWrapper(string $class): bool
    {
        return in_array($class, [SendQueuedMailable::class, SendQueuedNotifications::class, CallQueuedListener::class], true);
    }
}
