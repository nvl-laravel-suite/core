<?php

declare(strict_types=1);

namespace Nvl\Support\Doctor;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Nvl\Support\Config\PackageConfiguration;
use Nvl\Support\Config\PackageOptions;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Locales\LocaleCatalogDiagnostics;
use Nvl\Support\OwnerRegistry;
use Throwable;

/**
 * Reports shared catalog conflicts and compatibility inputs without mutation.
 */
final readonly class CoreDoctor
{
    /**
     * Retain the shared configuration diagnostic boundaries.
     */
    public function __construct(
        private OwnerRegistry $owners,
        private LocaleCatalogDiagnostics $locales,
        private Repository $config,
        private OwnerIdentityDiagnostics $ownerRows,
        private DatabaseManager $database,
        private GlobalNames $globalNames,
    ) {}

    /**
     * Inspect shared identity and locale compatibility.
     *
     * @return list<DoctorCheck>
     */
    public function inspect(): array
    {
        $checks = [...$this->infrastructureChecks(), ...$this->databasePlatformChecks(), ...$this->queuePersistenceChecks()];
        try {
            $checks = [...$checks, ...$this->globalNames->diagnostics()];
        } catch (Throwable $exception) {
            $checks[] = new DoctorCheck('globals.configuration', 'error', false, $exception->getMessage());
        }
        foreach (PackageConfiguration::detectedLegacy($this->config) as $package => $canonical) {
            $checks[] = new DoctorCheck('configuration.legacy.'.$package, 'warning', false,
                "Legacy NVL-shaped configuration [{$package}] was detected. Move options to [{$canonical}] or select [{$package}] in nvl-core.compatibility.legacy_config.");
        }
        foreach ((array) $this->config->get('nvl-core.configuration.legacy_reads', []) as $package => $canonical) {
            if (! is_string($package) || ! is_string($canonical)) {
                $checks[] = new DoctorCheck('configuration.legacy_metadata', 'error', false, 'Legacy configuration diagnostic declarations must map package names to canonical names.');

                continue;
            }
            $checks[] = new DoctorCheck('configuration.legacy_read.'.$package, 'warning', false, "Legacy configuration [{$package}] is active; move options to [{$canonical}].");
        }
        foreach ((array) $this->config->get('nvl-core.configuration.legacy_env', []) as $legacy => $canonical) {
            if (! is_string($legacy) || ! is_string($canonical)) {
                $checks[] = new DoctorCheck('configuration.legacy_env_metadata', 'error', false, 'Legacy environment diagnostic declarations must map legacy names to canonical names.');

                continue;
            }
            $checks[] = new DoctorCheck('configuration.legacy_env.'.$legacy, 'warning', false, "Legacy env input [{$legacy}] is active; use [{$canonical}].");
        }
        foreach (PackageOptions::deprecations() as $source => $deprecation) {
            $message = "Configuration [{$source}] is deprecated; use [{$deprecation['replacement']}].";
            if ($deprecation['conflict']) {
                $message .= ' The explicit canonical value takes precedence over the legacy alias.';
            }
            $checks[] = new DoctorCheck('configuration.deprecated.'.$source, 'warning', false, $message);
        }

        try {
            $this->owners->all();
            foreach ($this->owners->errors() as $source => $message) {
                $checks[] = new DoctorCheck('owners.identity.'.substr(hash('sha256', $source), 0, 16), 'error', false, $message);
            }
            $checks = [...$checks, ...$this->ownerRows->inspect()];
            foreach ($this->owners->deprecations() as $source => $deprecation) {
                $checks[] = new DoctorCheck(
                    'owners.deprecated.'.$source,
                    'warning',
                    false,
                    "Owner configuration [{$source}] is deprecated; declare [{$deprecation['replacement']}] and reference its owner alias.",
                );
            }
        } catch (Throwable $exception) {
            $checks[] = new DoctorCheck('owners.configuration', 'error', false, $exception->getMessage());
        }

        $diagnostics = $this->locales->inspect();
        foreach (['error' => $diagnostics['errors'], 'warning' => $diagnostics['warnings']] as $severity => $messages) {
            foreach ($messages as $message) {
                $checks[] = new DoctorCheck('locales.'.$severity.'.'.substr(hash('sha256', $message), 0, 16), $severity, false, $message);
            }
        }

        return $checks;
    }

    /**
     * Validate effective shared names against configured backends without opening connections.
     *
     * @return list<DoctorCheck>
     */
    private function infrastructureChecks(): array
    {
        $settings = [
            'connection' => [static fn (): string => PackageOptions::connection('nvl-core'), 'database.connections'],
            'queue.connection' => [static fn (): string => PackageOptions::queueConnection('nvl-core'), 'queue.connections'],
            'queue.name' => [static fn (): string => PackageOptions::queueName('nvl-core'), null],
            'locks.store' => [static fn (): string => PackageOptions::lockStore('nvl-core'), 'cache.stores'],
            'authorization.guard' => [static fn (): string => PackageOptions::authGuard('nvl-core'), 'auth.guards'],
        ];
        $checks = [];

        foreach ($settings as $key => [$resolve, $backend]) {
            try {
                $name = $resolve();
                if ($backend !== null) {
                    $configuration = $this->config->get($backend.'.'.$name);
                    if (! is_array($configuration)
                        || ! is_string($configuration['driver'] ?? null)
                        || trim($configuration['driver']) === '') {
                        throw new InvalidArgumentException("Core [{$key}] selects an unconfigured backend [{$name}].");
                    }
                }
                $checks[] = new DoctorCheck('configuration.'.$key, 'info', true, "Effective Core [{$key}] is [{$name}].");
            } catch (Throwable $exception) {
                $checks[] = new DoctorCheck('configuration.'.$key, 'error', false, $exception->getMessage());
            }
        }

        return $checks;
    }

    /**
     * Reject unsupported selected database drivers without opening a connection.
     *
     * @return list<DoctorCheck>
     */
    private function databasePlatformChecks(): array
    {
        try {
            $connection = PackageOptions::connection('nvl-core');
            $driver = $this->config->get('database.connections.'.$connection.'.driver');
            if ($driver === 'sqlsrv') {
                return [new DoctorCheck('database.platform', 'error', false,
                    'SQL Server is unsupported. Select SQLite for fast development, PostgreSQL 17, MySQL 8.4, or MariaDB 12.3 for a supported NVL database profile.')];
            }

            return [new DoctorCheck('database.platform', 'info', true, 'The selected database driver is not SQL Server.')];
        } catch (Throwable $exception) {
            return [new DoctorCheck('database.platform', 'error', false, $exception->getMessage())];
        }
    }

    /** @return list<DoctorCheck> Explicit diagnostics for raw rejected-envelope persistence */
    private function queuePersistenceChecks(): array
    {
        $driver = $this->config->get('queue.failed.driver', 'database-uuids');
        if ($driver === null || $driver === 'null') {
            return [new DoctorCheck('queue.quarantine.persistence', 'warning', false,
                'Native failed-job persistence is disabled. Rejected NVL queue envelopes are deleted safely but cannot be retained for raw retry; configure a persistent queue.failed driver.')];
        }
        if (! in_array($driver, ['database', 'database-uuids'], true)) {
            return [];
        }
        try {
            if (PackageOptions::queueConnection('core') === 'sync') {
                return [];
            }
            $connection = $this->config->get('queue.failed.database') ?? $this->config->get('database.default');
            $table = $this->config->get('queue.failed.table', 'failed_jobs');
            if (! is_string($connection) || ! is_string($table) || $table === '') {
                throw new InvalidArgumentException('Native failed-job database and table must be configured.');
            }
            if (! $this->database->connection($connection)->getSchemaBuilder()->hasTable($table)) {
                throw new InvalidArgumentException("Native failed-job table [{$connection}.{$table}] is unavailable.");
            }
        } catch (Throwable $exception) {
            return [new DoctorCheck('queue.quarantine.persistence', 'error', false, $exception->getMessage())];
        }

        return [];
    }
}
