<?php

declare(strict_types=1);

namespace Nvl\Support\Doctor;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Nvl\Support\Config\PackageOptions;
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
    ) {}

    /**
     * Inspect shared identity and locale compatibility.
     *
     * @return list<DoctorCheck>
     */
    public function inspect(): array
    {
        $checks = $this->infrastructureChecks();
        foreach (PackageOptions::deprecations() as $source => $deprecation) {
            $message = "Configuration [{$source}] is deprecated; use [{$deprecation['replacement']}].";
            if ($deprecation['conflict']) {
                $message .= ' The explicit canonical value takes precedence over the legacy alias.';
            }
            $checks[] = new DoctorCheck('configuration.deprecated.'.$source, 'warning', false, $message);
        }

        try {
            $this->owners->all();
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
}
