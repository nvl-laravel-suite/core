<?php

declare(strict_types=1);

namespace Nvl\Support\Doctor;

use Composer\InstalledVersions;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Schema\SchemaIdentities;
use Throwable;

/** Inventories retained NVL morph types only during an explicit diagnostic. */
final readonly class OwnerIdentityDiagnostics
{
    /** @var array<string, array<string, list<string>>> Capability owner columns; actors and authentication principals are separate boundaries. */
    private const array OWNER_COLUMNS = [
        'comments' => ['comments' => ['commentable_type']],
        'content' => ['placements' => ['owner_type']],
        'media' => ['associations' => ['associable_type'], 'owner_slot_operations' => ['owner_type']],
        'metafields' => ['metafields' => ['metafieldable_type']],
        'seo' => ['profiles' => ['seoable_type']],
        'tasks' => ['assignments' => ['assignee_type']],
        'taxonomy' => ['termables' => ['termable_type']],
        'templates' => ['assignments' => ['owner_type']],
    ];

    /** Retain capability and database boundaries without opening connections at boot. */
    public function __construct(private OwnerRegistry $owners, private DatabaseManager $database) {}

    /** @return list<DoctorCheck> Historical identity mismatches without data mutation */
    public function inspect(): array
    {
        $models = array_values($this->owners->all());
        if ($models === []) {
            return [];
        }
        $checks = [];
        foreach (SchemaIdentities::all() as $package => $definition) {
            if (! InstalledVersions::isInstalled('nvl/'.$package)) {
                continue;
            }
            foreach ($definition['tables'] as $key => $table) {
                $ownerTypes = self::OWNER_COLUMNS[$package][$key] ?? [];
                if ($ownerTypes === []) {
                    continue;
                }
                try {
                    $connection = $this->database->connection(PackageStorage::tableConnection($package, $key));
                    $name = PackageStorage::table($package, $key, $table['default']);
                    $schema = $connection->getSchemaBuilder();
                    if (! $schema->hasTable($name)) {
                        continue;
                    }
                    foreach ($ownerTypes as $column) {
                        if (! $schema->hasColumn($name, $column)) {
                            continue;
                        }
                        foreach ($connection->table($name)->whereNotNull($column)->distinct()->pluck($column) as $stored) {
                            if (! is_string($stored)) {
                                continue;
                            }
                            try {
                                $model = $this->owners->model(Relation::getMorphedModel($stored) ?? $stored);
                            } catch (InvalidArgumentException) {
                                $model = null;
                            }
                            $current = $model !== null && in_array($model, $models, true)
                                ? (new $model)->getMorphClass() : null;
                            if ($current === $stored) {
                                continue;
                            }
                            $connectionName = $connection->getName();
                            $message = "Package [{$package}] connection [{$connectionName}] table [{$name}] column [{$column}] retains owner type [{$stored}]";
                            $message .= $current === null
                                ? ' without a declared current owner. Declare its class/read bridge and reconcile retained data explicitly.'
                                : " while Laravel writes [{$current}]. Reconcile retained data explicitly; Doctor has changed no rows.";
                            $checks[] = new DoctorCheck('owners.rows.'.substr(hash('sha256', "{$package}:{$connectionName}:{$name}:{$column}:{$stored}"), 0, 20), 'error', false, $message);
                        }
                    }
                } catch (Throwable $exception) {
                    $checks[] = new DoctorCheck('owners.storage.'.$package.'.'.$key, 'error', false,
                        "Owner inventory for [{$package}.{$key}] could not be read: ".$exception->getMessage());
                }
            }
        }

        return $checks;
    }
}
