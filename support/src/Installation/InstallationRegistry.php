<?php

declare(strict_types=1);

namespace Nvl\Support\Installation;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/** Aggregates metadata from loaded providers without discovering or enabling other packages. @api */
final readonly class InstallationRegistry
{
    /** Retain only the host container's declared metadata contributors. */
    public function __construct(private Container $container) {}

    /**
     * Merge identical component metadata and reject conflicting publication paths.
     *
     * @return list<PackageInstallation>
     */
    public function all(): array
    {
        $packages = [];
        foreach ($this->container->tagged(InstallationContributor::class) as $contributor) {
            if (! $contributor instanceof InstallationContributor) {
                throw new InvalidArgumentException('Installation contributors must supply immutable package metadata.');
            }
            $installation = $contributor->installation();
            $packages[$installation->package] ??= [];
            foreach ($installation->configuration as $key => $entry) {
                $existing = $packages[$installation->package][$key] ?? null;
                if ($existing !== null && $existing !== $entry) {
                    throw new InvalidArgumentException('Conflicting installation configuration ['.$key.'].');
                }
                $packages[$installation->package][$key] = $entry;
            }
        }
        ksort($packages);
        $result = [];
        foreach ($packages as $package => $configuration) {
            ksort($configuration);
            $result[] = new PackageInstallation($package, $configuration);
        }

        return $result;
    }

    /**
     * Select only loaded packages by logical or canonical Composer name.
     *
     * @param  list<string>  $packages
     * @return list<PackageInstallation>
     */
    public function select(array $packages): array
    {
        $all = [];
        foreach ($this->all() as $installation) {
            $all[$installation->package] = $installation;
        }
        if ($packages === []) {
            return array_values($all);
        }
        $selected = [];
        foreach ($packages as $package) {
            $name = str_starts_with($package, 'nvl/') ? $package : 'nvl/'.$package;
            if (! isset($all[$name])) {
                throw new InvalidArgumentException('Package ['.$package.'] is unknown or its provider is not loaded.');
            }
            $selected[$name] = $all[$name];
        }
        ksort($selected);

        return array_values($selected);
    }
}
