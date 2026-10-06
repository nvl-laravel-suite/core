<?php

declare(strict_types=1);

namespace Nvl\Support\Installation;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Nvl\Support\Bindings\RequiredBindings;
use RuntimeException;

/** Publishes declared config templates while preserving host files and explicit capability choices. @api */
final readonly class ConfigPublisher
{
    /** Retain loaded metadata, host paths and the shared required-binding declarations. */
    public function __construct(
        private Application $application,
        private InstallationRegistry $registry,
        private RequiredBindings $requiredBindings,
    ) {}

    /**
     * Publish only selected config files and return metadata-only capability guidance.
     *
     * @param  list<string>  $packages
     */
    public function publish(array $packages, bool $dryRun = false, bool $force = false): InstallReport
    {
        $selected = $this->registry->select($packages);
        $names = array_map(static fn (PackageInstallation $installation): string => $installation->package, $selected);
        $root = rtrim($this->application->configPath(), DIRECTORY_SEPARATOR);
        if (! str_starts_with($root, rtrim($this->application->basePath(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Config publication must use a config directory inside the host application.');
        }
        if (is_link($root) || (file_exists($root) && ! is_dir($root))) {
            throw new RuntimeException('The host config directory must be a normal directory, not a symbolic link.');
        }

        $plan = [];
        foreach ($selected as $installation) {
            foreach ($installation->configuration as $key => $entry) {
                $source = realpath($entry['source']);
                $destination = $root.DIRECTORY_SEPARATOR.$entry['target'];
                $this->assertDestination($root, $destination);
                if ($source === false || ! is_file($source) || ! is_readable($source)) {
                    throw new RuntimeException('The selected package config template ['.$key.'] is unavailable.');
                }
                if (isset($plan[$destination]) && $plan[$destination] !== $source) {
                    throw new InvalidArgumentException('Multiple packages claim a conflicting config destination.');
                }
                $plan[$destination] = $source;
            }
        }
        ksort($plan);

        $bindings = [];
        $definitions = [];
        foreach ($this->requiredBindings->all() as $definition) {
            $package = $this->canonicalPackage($definition->package);
            $definitions[$package.'|'.$definition->contract] = $definition;
        }
        $missingEnabledBindings = false;
        foreach ($this->requiredBindings->inspect() as $status) {
            $package = $this->canonicalPackage($status->package);
            if (! in_array($package, $names, true)) {
                continue;
            }
            $bindings[] = $status;
            $definition = $definitions[$package.'|'.$status->contract] ?? null;
            if ($status->status === 'missing' && $definition !== null && $definition->enabledWhen !== null) {
                $missingEnabledBindings = true;
            }
        }

        if ($dryRun || $plan === []) {
            $results = [];
            foreach ($plan as $destination => $source) {
                $exists = is_file($destination);
                $results[] = ['path' => $destination, 'result' => $exists ? ($force ? 'would_replace' : 'preserved') : 'would_publish', 'backup' => null];
            }

            return new InstallReport($names, $results, $bindings, $missingEnabledBindings);
        }

        if (! is_dir($root) && ! mkdir($root, 0755, true) && ! is_dir($root)) {
            throw new RuntimeException('The host config directory cannot be created.');
        }
        $lockPath = $root.DIRECTORY_SEPARATOR.'.nvl-install.lock';
        $this->assertDestination($root, $lockPath);
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('The config publication lock cannot be opened.');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('The config publication lock cannot be acquired.');
            }
            foreach ($plan as $destination => $source) {
                $this->assertDestination($root, $destination);
            }
            $results = [];
            foreach ($plan as $destination => $source) {
                if (is_file($destination) && ! $force) {
                    $results[] = ['path' => $destination, 'result' => 'preserved', 'backup' => null];

                    continue;
                }
                $backup = null;
                $mode = 0644;
                if (is_file($destination)) {
                    $permissions = fileperms($destination);
                    $mode = is_int($permissions) ? $permissions & 0777 : 0600;
                    $backup = $destination.'.backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(8));
                    $this->assertDestination($root, $backup);
                    if (! copy($destination, $backup) || ! chmod($backup, $mode)) {
                        throw new RuntimeException('The existing config cannot be backed up safely.');
                    }
                }
                $contents = file_get_contents($source);
                if (! is_string($contents)) {
                    throw new RuntimeException('The selected config template cannot be read.');
                }
                $temporary = tempnam($root, '.nvl-install-');
                if ($temporary === false || dirname($temporary) !== realpath($root)) {
                    throw new RuntimeException('A same-directory config temporary file cannot be created.');
                }
                try {
                    if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || ! chmod($temporary, $mode)) {
                        throw new RuntimeException('The config template cannot be written completely.');
                    }
                    $this->assertDestination($root, $destination);
                    if (! rename($temporary, $destination)) {
                        throw new RuntimeException('The config cannot be published atomically.');
                    }
                } finally {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
                $results[] = ['path' => $destination, 'result' => 'published', 'backup' => $backup];
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return new InstallReport($names, $results, $bindings, $missingEnabledBindings);
    }

    /** Validate every target before writes and reject linked or non-file destinations. */
    private function assertDestination(string $root, string $destination): void
    {
        if (dirname($destination) !== $root || str_contains($destination, "\0")
            || is_link($root) || is_link($destination)
            || (file_exists($destination) && ! is_file($destination))) {
            throw new RuntimeException('Config publication paths must remain normal files in the host config directory.');
        }
        $base = realpath($this->application->basePath());
        $directory = realpath($root);
        if ($base !== false && $directory !== false && ! str_starts_with($directory, $base.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The config directory escapes the host application root.');
        }
        $relative = substr($root, strlen(rtrim($this->application->basePath(), DIRECTORY_SEPARATOR)) + 1);
        $current = rtrim($this->application->basePath(), DIRECTORY_SEPARATOR);
        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('The config directory contains unsafe path components.');
            }
            $current .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($current)) {
                throw new RuntimeException('The config directory traverses a symbolic link.');
            }
        }
    }

    /** Normalize package identities without inferring capability enablement. */
    private function canonicalPackage(string $package): string
    {
        return str_starts_with($package, 'nvl/') ? $package : 'nvl/'.$package;
    }
}
