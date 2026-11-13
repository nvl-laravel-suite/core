<?php

declare(strict_types=1);

namespace Nvl\Support\Doctor;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Nvl\Support\Config\PackageOptions;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Locales\LocaleCatalogDiagnostics;
use Nvl\Support\OwnerRegistry;
use Throwable;

/**
 * Reports native configuration and identity conflicts without mutation.
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
     * Inspect shared identity, locales and infrastructure.
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

        try {
            $this->owners->all();
            foreach ($this->owners->errors() as $source => $message) {
                $checks[] = new DoctorCheck('owners.identity.'.substr(hash('sha256', $source), 0, 16), 'error', false, $message);
            }
            $checks = [...$checks, ...$this->ownerRows->inspect()];
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
            if (! in_array($driver, ['sqlite', 'pgsql'], true)) {
                $platform = $driver === 'sqlsrv' ? 'SQL Server' : (is_string($driver) ? $driver : 'The selected database driver');

                return [new DoctorCheck('database.platform', 'error', false,
                    $platform.' is unsupported. Select SQLite or PostgreSQL for a supported NVL database profile.')];
            }

            return [new DoctorCheck('database.platform', 'info', true, 'The selected database driver is supported.')];
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
