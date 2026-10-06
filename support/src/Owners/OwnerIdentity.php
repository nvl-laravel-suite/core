<?php

declare(strict_types=1);

namespace Nvl\Support\Owners;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Captures an exact native owner identity without admitting storage or a policy.
 *
 * @api
 */
final readonly class OwnerIdentity
{
    /**
     * Retain validated native identity and declared connection metadata.
     *
     * @param  class-string<Model>  $model
     */
    private function __construct(
        public string $type,
        public string $id,
        public string $model,
        public ?string $connection,
    ) {}

    /**
     * Capture a persisted model's native scalar identity without queries.
     *
     * @throws InvalidArgumentException
     */
    public static function fromModel(Model $owner): self
    {
        $key = $owner->getAttributes()[$owner->getKeyName()] ?? null;
        if (! $owner->exists || (! is_int($key) && ! is_string($key)) || (is_string($key) && trim($key) === '')) {
            throw new InvalidArgumentException('Batched reads require persisted owners with nonempty scalar keys.');
        }
        $key = $owner->getKey();
        if ((! is_int($key) && ! is_string($key)) || (is_string($key) && trim($key) === '')) {
            throw new InvalidArgumentException('Batched reads require native scalar owner keys.');
        }
        $type = $owner->getMorphClass();
        if (trim($type) === '') {
            throw new InvalidArgumentException('Batched reads require a native morph identity.');
        }

        return new self($type, (string) $key, $owner::class, $owner->getConnectionName());
    }
}
