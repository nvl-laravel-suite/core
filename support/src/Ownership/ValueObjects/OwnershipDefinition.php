<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\ValueObjects;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use ReflectionClass;

/**
 * Describes a single canonical primary owner and its supported persistence shape.
 *
 * @api
 */
final readonly class OwnershipDefinition
{
    /**
     * Retain validated immutable ownership metadata.
     *
     * @param  class-string<Model>|null  $owner
     */
    private function __construct(
        public string $strategy,
        public ?string $owner = null,
        public ?string $foreignKey = null,
        public ?string $table = null,
        public ?string $resourceKey = null,
        public ?string $ownerKey = null,
        public ?string $relation = null,
    ) {}

    /** Declare unchanged storage that has never adopted ownership. */
    public static function unscoped(): self
    {
        return new self('unscoped');
    }

    /** Declare a native owner foreign key on a root. */
    public static function column(string $owner, string $foreignKey): self
    {
        self::assertOwner($owner);
        self::assertIdentifier($foreignKey);

        return new self('column', owner: $owner, foreignKey: $foreignKey);
    }

    /** Declare one exclusive primary owner through a concrete native-key pivot. */
    public static function pivot(string $owner, string $table, string $resourceKey, string $ownerKey): self
    {
        self::assertOwner($owner);
        foreach ([$table, $resourceKey, $ownerKey] as $identifier) {
            self::assertIdentifier($identifier);
        }
        if ($resourceKey === $ownerKey) {
            throw new OwnershipConfigurationInvalid('Ownership pivot keys must identify separate records.');
        }

        return new self('pivot', owner: $owner, table: $table, resourceKey: $resourceKey, ownerKey: $ownerKey);
    }

    /** Declare ownership inherited through a canonical package-approved parent. */
    public static function inherited(string $relation): self
    {
        self::assertIdentifier($relation);

        return new self('inherited', relation: $relation);
    }

    /**
     * Declare a package-owned operational root captured from admitted context.
     *
     * @internal
     */
    public static function captured(): self
    {
        return new self('captured');
    }

    /** @phpstan-assert class-string<Model> $owner */
    private static function assertOwner(string $owner): void
    {
        if (! is_a($owner, Model::class, true) || ! (new ReflectionClass($owner))->isInstantiable()) {
            throw new OwnershipConfigurationInvalid('Ownership requires a concrete native Eloquent owner model.');
        }
    }

    /** Reject identifiers that change the declared query structure. */
    private static function assertIdentifier(string $identifier): void
    {
        if (strlen($identifier) > 100 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
            throw new OwnershipConfigurationInvalid('Ownership identifiers must be explicit bounded SQL or relation names.');
        }
    }
}
