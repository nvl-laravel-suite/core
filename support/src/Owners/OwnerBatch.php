<?php

declare(strict_types=1);

namespace Nvl\Support\Owners;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Validates finite owner input and retains first-request identity order.
 *
 * This value does not prove authorization, tenant admission or persisted attributes.
 *
 * @api
 */
final readonly class OwnerBatch
{
    public const int MAXIMUM_OWNERS = 100;

    /**
     * Retain deduplicated models and their immutable identity snapshots.
     *
     * @param  list<Model>  $models
     * @param  list<OwnerIdentity>  $identities
     */
    private function __construct(private array $models, private array $identities) {}

    /**
     * Validate complete input and reject ambiguous duplicate metadata before queries.
     *
     * @param  array<array-key, mixed>  $owners
     *
     * @throws InvalidArgumentException
     */
    public static function fromModels(array $owners): self
    {
        if (! array_is_list($owners) || count($owners) > self::MAXIMUM_OWNERS) {
            throw new InvalidArgumentException('Batched reads accept a list of at most 100 persisted owners.');
        }
        $models = [];
        $identities = [];
        $seen = [];
        $classes = [];
        foreach ($owners as $owner) {
            if (! $owner instanceof Model) {
                throw new InvalidArgumentException('Every batched owner must be an Eloquent model.');
            }
            $identity = OwnerIdentity::fromModel($owner);
            if (isset($classes[$identity->type]) && $classes[$identity->type] !== $identity->model) {
                throw new InvalidArgumentException('A native owner identity cannot represent multiple concrete classes.');
            }
            $classes[$identity->type] = $identity->model;
            $previous = $seen[$identity->type][$identity->id] ?? null;
            if ($previous instanceof Model) {
                $previousAttributes = $previous->getAttributes();
                $currentAttributes = $owner->getAttributes();
                ksort($previousAttributes);
                ksort($currentAttributes);
                if ($previous->getConnectionName() !== $identity->connection || $previousAttributes !== $currentAttributes) {
                    throw new InvalidArgumentException('Duplicate owner identities contain conflicting connection or attribute metadata.');
                }

                continue;
            }
            $seen[$identity->type][$identity->id] = $owner;
            $models[] = $owner;
            $identities[] = $identity;
        }

        return new self($models, $identities);
    }

    /**
     * Return live model references requiring package-owned canonical admission.
     *
     * The immutable identities remain authoritative if these references change.
     *
     * @return list<Model>
     */
    public function owners(): array
    {
        return $this->models;
    }

    /**
     * Return exact identities in first-request order.
     *
     * @return list<OwnerIdentity>
     */
    public function identities(): array
    {
        return $this->identities;
    }

    /** Determine whether the caller requested no owners. */
    public function isEmpty(): bool
    {
        return $this->models === [];
    }
}
