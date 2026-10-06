<?php

declare(strict_types=1);

namespace Nvl\Support\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Nvl\Support\Config\PackageStorage;

/**
 * Plans verified legacy renames before making any database changes.
 *
 * @phpstan-type UpgradeStep array{kind: 'table'|'migration', package: string, connection: string|null, from: string, to: string}
 * @phpstan-type UpgradePlan array{schema_version: int, packages: list<string>, steps: list<UpgradeStep>, warnings: list<string>}
 */
final readonly class SchemaUpgrade
{
    /** Retain the host database resolver for explicitly selected packages. */
    public function __construct(private DatabaseManager $database) {}

    /**
     * Validate all selected packages before returning a reviewable plan.
     *
     * @param  list<string>  $packages
     * @return UpgradePlan
     */
    public function plan(array $packages, bool $claimLegacy): array
    {
        if (! $claimLegacy) {
            throw new LogicException('Legacy schema ownership requires --claim-legacy. Inspect nvl:doctor and use --dry-run before applying the plan.');
        }
        if ($packages === []) {
            throw new LogicException('Select at least one --package; upgrades never claim every installed package implicitly.');
        }
        $repository = $this->database->connection();
        $migrationTable = $this->migrationTable();
        $rows = $repository->getSchemaBuilder()->hasTable($migrationTable)
            ? $repository->table($migrationTable)->pluck('migration')->all()
            : [];
        $steps = [];
        $warnings = [];
        $targets = [];
        foreach (array_values(array_unique($packages)) as $package) {
            $manifest = SchemaIdentities::package($package);
            if ($manifest === [] || ! is_array(config($manifest['config']))) {
                throw new LogicException("Schema package [{$package}] is unknown or not loaded in this application.");
            }
            $connectionName = PackageStorage::connection($package);
            $connection = $this->database->connection($connectionName);
            $schema = $connection->getSchemaBuilder();
            $claimed = [];
            foreach ($manifest['migrations'] as $old => $migration) {
                $candidates = array_values(array_filter($rows, static fn (mixed $row): bool => is_string($row) && ($row === $old || substr($row, 18) === substr($old, 18))));
                if (count($candidates) > 1) {
                    throw new LogicException("Migration identity [{$old}] is ambiguous; resolve the duplicate published records before upgrading.");
                }
                if ($candidates === []) {
                    continue;
                }
                foreach ($migration['creates'] as $key) {
                    if (! SchemaPreflight::managed($package, $key)) {
                        continue;
                    }
                    $tableSchema = $this->database->connection(PackageStorage::tableConnection($package, $key))->getSchemaBuilder();
                    if (! $tableSchema->hasTable($manifest['tables'][$key]['legacy'])
                        && ! $tableSchema->hasTable(PackageStorage::table($package, $key, $manifest['tables'][$key]['default']))) {
                        throw new LogicException("Migration [{$old}] has incomplete NVL storage at [{$key}]. Its name alone does not prove package ownership.");
                    }
                }
                $from = $candidates[0];
                if ($from !== $old) {
                    $published = database_path('migrations/'.$from.'.php');
                    if (! is_file($published) || hash_file('sha256', $published) !== $migration['legacy_checksum']) {
                        throw new LogicException("Published migration [{$from}] cannot be verified against the released checksum. Preserve the file and resolve its ownership before upgrading.");
                    }
                }
                $to = $migration['name'];
                if (in_array($to, $rows, true)) {
                    throw new LogicException("Both legacy and canonical migration records exist for [{$from}]; the command will not remove either record.");
                }
                foreach ($migration['creates'] as $key) {
                    $claimed[$key] = true;
                }
                $steps[] = ['kind' => 'migration', 'package' => $package, 'connection' => null, 'from' => $from, 'to' => $to];
            }
            $verifiedTables = 0;
            foreach ($manifest['tables'] as $key => $table) {
                if (! SchemaPreflight::managed($package, $key)) {
                    continue;
                }
                $connectionName = PackageStorage::tableConnection($package, $key);
                $connection = $this->database->connection($connectionName);
                $schema = $connection->getSchemaBuilder();
                $from = $table['legacy'];
                $to = PackageStorage::table($package, $key, $table['default']);
                if ($from !== $to && str_contains($to, '.')) {
                    throw new LogicException('Legacy upgrades require an unqualified target table; move storage between schemas explicitly before upgrading.');
                }
                $targetIdentity = $connection->getName().'|'.$to;
                if (isset($targets[$targetIdentity])) {
                    throw new LogicException("Tables [{$targets[$targetIdentity]}] and [{$from}] share configured target [{$to}]. No tables have been renamed.");
                }
                $targets[$targetIdentity] = $from;
                $legacyExists = $schema->hasTable($from);
                $targetExists = $schema->hasTable($to);
                if (! $legacyExists && ! $targetExists) {
                    continue;
                }
                $recorded = isset($claimed[$key]);
                if (! $recorded) {
                    foreach ($table['create_migrations'] as $creator) {
                        $newSuffix = substr($manifest['migrations'][$creator]['name'], 18);
                        $recorded = $recorded || array_any($rows, static fn (mixed $row): bool => is_string($row) && substr($row, 18) === $newSuffix);
                    }
                }
                if (! $recorded) {
                    throw new LogicException("Existing table [{$from}] has no verified NVL creating migration. The command will not claim host-owned storage.");
                }
                if ($from !== $to && $legacyExists && $targetExists) {
                    throw new LogicException("Both [{$from}] and [{$to}] exist. Resolve this collision before upgrading; no tables have been renamed.");
                }
                SchemaShape::assertCompatible($schema, $legacyExists ? $from : $to, $table['columns'], $table['keys'], $table['foreign_keys']);
                $verifiedTables++;
                if ($legacyExists && $from !== $to) {
                    if (! $this->supportsRenameTransactions($connection)) {
                        $warnings[] = "Connection [{$connection->getName()}] cannot roll back table renames. Each completed step remains inspectable and a rerun resumes from verified storage.";
                    }
                    $steps[] = ['kind' => 'table', 'package' => $package, 'connection' => $connectionName, 'from' => $from, 'to' => $to];
                }
            }
            if ($verifiedTables === 0 && array_any($steps, static fn (array $step): bool => $step['package'] === $package)) {
                throw new LogicException("Package [{$package}] has no verified storage. Unrelated migration records will remain unchanged.");
            }

        }
        usort($steps, static fn (array $left, array $right): int => ($left['kind'] === 'table' ? 0 : 1) <=> ($right['kind'] === 'table' ? 0 : 1));
        if (count(array_unique(array_map(fn (array $step): ?string => $this->database->connection($step['connection'])->getName(), $steps))) > 1) {
            $warnings[] = 'Selected storage spans connections; transactions are per connection, not atomic across databases.';
        }

        return ['schema_version' => 1, 'packages' => array_values(array_unique($packages)), 'steps' => $steps, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * Revalidate immediately before executing the explicitly approved plan.
     *
     * @param  UpgradePlan  $plan
     */
    public function execute(array $plan): void
    {
        if ($this->plan($plan['packages'], true) !== $plan) {
            throw new LogicException('Storage changed after the upgrade plan was prepared. Generate a fresh dry run before applying it.');
        }
        $groups = [];
        foreach ($plan['steps'] as $step) {
            $name = $this->database->connection($step['connection'])->getName();
            $groups[$name][] = $step;
        }
        foreach ($groups as $name => $steps) {
            $connection = $this->database->connection($name);
            $apply = function () use ($connection, $steps): void {
                foreach ($steps as $step) {
                    if ($step['kind'] === 'table') {
                        $connection->getSchemaBuilder()->rename($step['from'], $step['to']);
                    } else {
                        $updated = $connection->table($this->migrationTable())->where('migration', $step['from'])->update(['migration' => $step['to']]);
                        if ($updated !== 1) {
                            throw new LogicException("Migration record [{$step['from']}] changed during upgrade.");
                        }
                    }
                }
            };
            if ($this->supportsRenameTransactions($connection)) {
                $connection->transaction($apply);
            } else {
                $apply();
            }
        }
    }

    /** SQLite can transact these rename statements despite Laravel's general schema grammar flag. */
    private function supportsRenameTransactions(Connection $connection): bool
    {
        return $connection->getDriverName() === 'sqlite' || $connection->getSchemaGrammar()->supportsSchemaTransactions();
    }

    /** Resolve Laravel's actual migration repository table. */
    private function migrationTable(): string
    {
        $setting = config('database.migrations', ['table' => 'migrations']);
        $table = is_array($setting) ? ($setting['table'] ?? 'migrations') : $setting;
        if (! is_string($table) || $table === '') {
            throw new LogicException('The migration repository table is invalid.');
        }

        return $table;
    }
}
