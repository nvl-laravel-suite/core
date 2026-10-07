<?php

declare(strict_types=1);

namespace Nvl\Support\Schema;

use Composer\InstalledVersions;
use LogicException;

/**
 * Establishes ownership from exact installed files and explicit published declarations.
 *
 * @phpstan-type OwnedMigration array{package: string, name: string, legacy: string, path: string, canonical: string, published: bool, current: bool, recorded: string|null}
 */
final class SchemaMigrationPaths
{
    /**
     * Identify a file before reading its contents or package storage.
     *
     * @return OwnedMigration|null
     */
    public function identity(string $path): ?array
    {
        $real = realpath($path);
        if ($real === false || ! is_file($real)) {
            return null;
        }
        foreach ($this->vendor() as $identity) {
            if ($real === $identity['path']) {
                return $identity;
            }
        }
        foreach ($this->declarations() as $declared => $definition) {
            if (realpath($declared) === $real) {
                return $this->published($declared, $definition);
            }
        }

        return null;
    }

    /**
     * Return the exact executable files shipped by installed schema packages.
     *
     * @return list<OwnedMigration>
     */
    public function vendor(): array
    {
        $files = [];
        foreach (SchemaIdentities::all() as $package => $manifest) {
            if (! InstalledVersions::isInstalled('nvl/'.$package)) {
                continue;
            }
            $root = InstalledVersions::getInstallPath('nvl/'.$package);
            if ($root === null) {
                continue;
            }
            foreach ($manifest['migrations'] as $old => $migration) {
                $path = realpath($root.'/'.$migration['path']);
                if ($path !== false && is_file($path)) {
                    $files[] = ['package' => $package, 'name' => $migration['name'], 'legacy' => $old, 'path' => $path, 'canonical' => $path, 'published' => false, 'current' => true, 'recorded' => null];
                }
            }
        }

        return $files;
    }

    /**
     * Validate every host-declared published file for an explicit upgrade plan.
     *
     * @return list<OwnedMigration>
     */
    public function declared(): array
    {
        $files = [];
        foreach ($this->declarations() as $path => $definition) {
            $files[] = $this->published($path, $definition);
        }

        return $files;
    }

    /**
     * Read exact host declarations without inspecting any migration contents.
     *
     * @return array<string, array{package: string, migration: string, legacy?: string}>
     */
    private function declarations(): array
    {
        $declared = config('nvl-core.migrations.published', []);
        if (! is_array($declared)) {
            throw new LogicException('nvl-core.migrations.published must map exact absolute files to package and migration identities.');
        }
        $validated = [];
        foreach ($declared as $path => $definition) {
            if (! is_string($path) || ! str_starts_with($path, DIRECTORY_SEPARATOR) || ! is_array($definition)
                || ! is_string($definition['package'] ?? null) || ! is_string($definition['migration'] ?? null)
                || (isset($definition['legacy']) && ! is_string($definition['legacy']))) {
                throw new LogicException('Each published migration declaration requires an exact absolute path, package, migration and optional legacy record.');
            }
            $validated[$path] = ['package' => $definition['package'], 'migration' => $definition['migration']];
            if (isset($definition['legacy'])) {
                $validated[$path]['legacy'] = $definition['legacy'];
            }
        }

        return $validated;
    }

    /**
     * Verify only an explicitly claimed file against its selected released identity.
     *
     * @param  array{package: string, migration: string, legacy?: string}  $definition
     * @return OwnedMigration
     */
    private function published(string $path, array $definition): array
    {
        $canonical = array_find($this->vendor(), static fn (array $entry): bool => $entry['package'] === $definition['package'] && $entry['name'] === $definition['migration']);
        $real = realpath($path);
        if ($canonical === null || $real === false || ! is_file($real)) {
            throw new LogicException("Declared published migration [{$path}] or its installed canonical identity is unavailable.");
        }
        $migration = SchemaIdentities::package($canonical['package'])['migrations'][$canonical['legacy']] ?? null;
        if ($migration === null) {
            throw new LogicException('The installed migration manifest changed while resolving published ownership.');
        }
        $checksum = hash_file('sha256', $real);
        $current = $checksum === hash_file('sha256', $canonical['path']);
        if (! $current && $checksum !== $migration['legacy_checksum'] && $checksum !== $migration['previous_checksum']) {
            throw new LogicException("Declared published migration [{$path}] does not match the released checksum; reconcile modified host code explicitly.");
        }

        return [...$canonical, 'path' => $real, 'published' => true, 'current' => $current, 'recorded' => $definition['legacy'] ?? null];
    }
}
