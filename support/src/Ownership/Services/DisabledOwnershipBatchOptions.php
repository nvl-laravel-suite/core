<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Nvl\Support\Ownership\Contracts\OwnershipBatchOptions;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;
use Throwable;

/** Rejects captured callback authority when the optional runtime is absent. @internal */
final readonly class DisabledOwnershipBatchOptions implements OwnershipBatchOptions
{
    /** Use the explicit disabled boundary even if a host replaces the general queue context. */
    public function __construct(private DisabledOwnershipQueueContext $context) {}

    /** Parse only inert scalar metadata, preserving native callback verification afterward. */
    public function validate(string $serialized): void
    {
        set_error_handler(static function (): never {
            throw new OwnershipViolation('Native batch options are malformed.');
        });
        try {
            $options = unserialize($serialized, ['allowed_classes' => false, 'max_depth' => 64]);
        } catch (Throwable $exception) {
            throw new OwnershipViolation('Native batch options are malformed.', $exception);
        } finally {
            restore_error_handler();
        }
        if (! is_array($options) || (array_key_exists('nvl_ownership', $options) && ! is_array($options['nvl_ownership']))) {
            throw new OwnershipViolation('Native batch options require scalar ownership metadata.');
        }
        $envelope = array_key_exists('nvl_ownership', $options) ? OwnershipJobEnvelope::fromArray($options['nvl_ownership']) : OwnershipJobEnvelope::fromSnapshot(new OwnershipSnapshot(OwnershipContextMode::Disabled));
        $this->context->run($envelope, static fn (): null => null);
    }
}
