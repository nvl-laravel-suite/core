<?php

declare(strict_types=1);

namespace Nvl\Support\Schema;

use Illuminate\Database\DatabaseManager;
use LogicException;
use Nvl\Support\Config\PackageStorage;

/** Validates explicitly selected owned migration targets without executing migrations. */
final readonly class SchemaPreflight
{
    /** Create a preflight against the application's configured connections. */
    public function __construct(private DatabaseManager $database, private SchemaMigrationPaths $paths) {}

    /**
     * Reject existing targets whose creating migration is still pending.
     *
     * @param  list<string>  $migrations
     */
    public function validate(array $migrations): void
    {
        $targets = [];
        $owned = [];
        $owners = [];
        foreach ($migrations as $path) {
            $identity = $this->paths->identity($path);
            if ($identity === null) {
                continue;
            }
            $key = $identity['package'].'|'.$identity['name'];
            if (isset($owners[$key]) && $owners[$key] !== $identity['path']) {
                throw new LogicException("Migration [{$identity['name']}] has two owners. Select vendor or explicitly declared published ownership before migrating.");
            }
            $owners[$key] = $identity['path'];
            if (! $identity['current']) {
                throw new LogicException('Declared legacy migration code must be archived or replaced before migration preflight; reconcile code and history together.');
            }
            $owned[$identity['path']] = $identity;
        }
        if ($owned === []) {
            return;
        }
        $migrationSetting = config('database.migrations', ['table' => 'migrations']);
        $migrationTable = is_array($migrationSetting) ? ($migrationSetting['table'] ?? 'migrations') : $migrationSetting;
        if (! is_string($migrationTable) || $migrationTable === '') {
            throw new LogicException('The migration repository table is invalid.');
        }
        $repository = $this->database->connection();
        $recorded = $repository->getSchemaBuilder()->hasTable($migrationTable)
            ? $repository->table($migrationTable)->pluck('migration')->all()
            : [];
        foreach ($owned as $path => $identity) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            if (in_array($name, $recorded, true)) {
                continue;
            }
            foreach (SchemaIdentities::all() as $package => $manifest) {
                foreach ($manifest['migrations'] as $old => $migration) {
                    if ($package !== $identity['package'] || $migration['name'] !== $identity['name']) {
                        continue;
                    }
                    foreach ($migration['creates'] as $key) {
                        if (! self::managed($package, $key)) {
                            continue;
                        }
                        $schema = $this->database->connection(PackageStorage::tableConnection($package, $key))->getSchemaBuilder();
                        $table = PackageStorage::table($package, $key, $manifest['tables'][$key]['default']);
                        $target = ($schema->getConnection()->getName() ?? $this->database->getDefaultConnection()).'|'.$table;
                        if (isset($targets[$target])) {
                            throw new LogicException("Pending migrations [{$targets[$target]}] and [{$name}] create the same target [{$table}]. Run nvl:doctor --strict and choose one migration owner before this batch runs.");
                        }
                        $targets[$target] = $name;
                        if ($schema->hasTable($table)) {
                            throw new LogicException("Package [{$package}] cannot create existing table [{$table}]. Run nvl:doctor --strict and use nvl:schema:upgrade for a verified legacy installation, or select an unused tables.{$key} name.");
                        }
                        $legacy = $manifest['tables'][$key]['legacy'];
                        if ($legacy !== $table && $schema->hasTable($legacy)
                            && array_any($recorded, static fn (mixed $row): bool => is_string($row) && substr($row, 18) === substr($old, 18))) {
                            throw new LogicException("Package [{$package}] has legacy storage [{$legacy}] and a recorded legacy creator. Run nvl:doctor --strict and resolve ownership with nvl:schema:upgrade --dry-run before creating parallel storage.");
                        }
                    }
                }
            }
        }
    }

    /** Preserve Auth's embedded preset and optional directory ownership. */
    public static function managed(string $package, string $key): bool
    {
        if ($package === 'tenancy' && $key === 'tenants') {
            return config('nvl-tenancy.directory.driver', 'package') === 'package';
        }
        if ($package !== 'auth') {
            return true;
        }
        $features = [
            'users' => 'principal_management',
            'roles' => 'rbac', 'permissions' => 'rbac', 'model_has_permissions' => 'rbac',
            'model_has_roles' => 'rbac', 'role_has_permissions' => 'rbac',
            'personal_access_tokens' => 'api_tokens', 'password_reset_tokens' => 'password',
            'clients' => 'clients', 'client_sessions' => 'clients', 'invitations' => 'invitations',
            'challenges' => 'magic_links', 'totp_credentials' => 'totp', 'passkeys' => 'passkeys',
            'recovery_codes' => 'recovery_codes', 'social_identities' => 'social_identities', 'audits' => 'audit',
        ];

        if (config('nvl-auth.migrations.install_all', false) === true || ! isset($features[$key])) {
            return true;
        }
        if (($key === 'clients' && config('nvl-auth.features.audit.enabled', false) === true)
            || ($key === 'challenges' && config('nvl-auth.features.security_codes.enabled', false) === true)) {
            return true;
        }

        return config('nvl-auth.features.'.$features[$key].'.enabled', false) === true;
    }
}
