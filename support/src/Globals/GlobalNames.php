<?php

declare(strict_types=1);

namespace Nvl\Support\Globals;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Translation\Loader;
use InvalidArgumentException;
use Nvl\Support\Config\PackageConfiguration;
use Nvl\Support\Doctor\DoctorCheck;

/** Reserves canonical package names without replacing host registrations. */
final class GlobalNames
{
    /** @var array<string, true> Registrations owned by this application boot. */
    private array $owned = [];

    /** Retain configuration and persist diagnostic declarations through config caching. */
    public function __construct(private readonly Repository $config) {}

    /**
     * Register a canonical name when the host has not reserved it.
     *
     * @param  callable(string): bool  $exists
     * @param  callable(string): void  $install
     */
    public function reserve(string $package, string $surface, string $name, callable $exists, callable $install, bool $append = false): bool
    {
        $key = $package.'.'.$surface.'.'.$name;
        if (isset($this->owned[$key])) {
            if ($append) {
                $install($name);
            }

            return true;
        }
        if ($exists($name)) {
            $this->record($key, "Global name collision for [{$name}] owned by [nvl/{$package}]; the host registration was preserved.");

            return false;
        }
        $install($name);
        $this->owned[$key] = true;

        return true;
    }

    /** Register a canonical translation namespace while preserving host hints. */
    public function translations(string $package, string $path, Loader $loader): void
    {
        $canonical = 'nvl-'.PackageConfiguration::logical($package);
        $exists = static fn (string $namespace): bool => array_key_exists($namespace, $loader->namespaces());
        $install = static fn (string $namespace) => $loader->addNamespace($namespace, $path);
        $this->reserve($package, 'translations', $canonical, $exists, $install);
    }

    /** @return list<DoctorCheck> Read persisted canonical name collision diagnostics. */
    public function diagnostics(): array
    {
        $messages = $this->config->get('nvl-core.configuration.global_names', []);
        if (! is_array($messages)) {
            throw new InvalidArgumentException('Global names diagnostic declarations must be a map.');
        }
        ksort($messages, SORT_STRING);
        $checks = [];
        foreach ($messages as $key => $message) {
            if (is_string($key) && is_string($message)) {
                $checks[] = new DoctorCheck('globals.'.$key, 'warning', false, $message);
            }
        }

        return $checks;
    }

    /** Store only names and ownership, never host values. */
    private function record(string $key, string $message): void
    {
        $messages = $this->config->get('nvl-core.configuration.global_names', []);
        $messages = is_array($messages) ? $messages : [];
        $messages[$key] = $message;
        $this->config->set('nvl-core.configuration.global_names', $messages);
    }
}
