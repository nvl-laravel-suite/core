<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Owned canonical model with observable constructions and configuration-driven storage. */
final class OwnershipStorageProbe extends Model implements Ownable
{
    use HasOwnership;

    public static int $constructed = 0;

    public static string $storageTable = 'ownership_storage_probes';

    public static ?string $storageConnection = null;

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        self::$constructed++;
        parent::__construct($attributes);
    }

    /** Restore the declared storage before each isolated scenario. */
    public static function resetProbe(): void
    {
        self::$constructed = 0;
        self::$storageTable = 'ownership_storage_probes';
        self::$storageConnection = null;
    }

    /** Resolve the table on every call, as configured package models do. */
    public function getTable(): string
    {
        return self::$storageTable;
    }

    /** Resolve the connection name on every call, as configured package models do. */
    public function getConnectionName(): ?string
    {
        return self::$storageConnection ?? parent::getConnectionName();
    }

    /** Declare a concrete host primary owner. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(PackageOwner::class, 'company_id');
    }
}
