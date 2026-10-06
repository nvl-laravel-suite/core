<?php

declare(strict_types=1);

namespace Nvl\Support\Config;

use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Config;

/** Reads canonical package env inputs with explicitly selected one-major fallbacks. */
final class PackageEnvironment
{
    /** Return a canonical env value respecting false, empty and explicitly null values. */
    public static function get(string $name, mixed $default = null): mixed
    {
        $repository = Env::getRepository();
        if ($repository->get($name) !== null) {
            return Env::get($name);
        }
        $enabled = Env::get('NVL_CORE_LEGACY_ENV', self::legacyEnabled()) === true;
        if (! $enabled || ! str_starts_with($name, 'NVL_')) {
            return $default;
        }
        $inventoryPath = dirname(__DIR__, 2).'/resources/global-names.json';
        $inventory = is_file($inventoryPath) ? json_decode((string) file_get_contents($inventoryPath), true) : [];
        $aliases = is_array($inventory) && is_array($inventory['env'] ?? null) ? $inventory['env'] : [];
        $legacy = $aliases[$name] ?? null;
        if (! is_string($legacy) || $repository->get($legacy) === null) {
            return $default;
        }
        Config::set('nvl-core.configuration.legacy_env.'.$legacy, $name);
        $application = Config::getFacadeApplication();
        if ($application instanceof Application && ! $application->isBooted()) {
            $application->booting(static function () use ($legacy, $name): void {
                Config::set('nvl-core.configuration.legacy_env.'.$legacy, $name);
            });
        }

        return Env::get($legacy);
    }

    /** Read an explicit published opt-in even when its config file is loaded later. */
    private static function legacyEnabled(): bool
    {
        $application = Config::getFacadeApplication();
        if ($application === null || ! $application->bound('config')) {
            return false;
        }
        if (Config::has('nvl-core.compatibility.legacy_env')) {
            return Config::get('nvl-core.compatibility.legacy_env') === true;
        }
        if (! $application instanceof Application || ! is_file($application->configPath('nvl-core.php'))) {
            return false;
        }
        $configuration = require $application->configPath('nvl-core.php');

        return is_array($configuration) && is_array($configuration['compatibility'] ?? null)
            && ($configuration['compatibility']['legacy_env'] ?? false) === true;
    }

    private function __construct() {}
}
