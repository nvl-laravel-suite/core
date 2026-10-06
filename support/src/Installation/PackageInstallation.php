<?php

declare(strict_types=1);

namespace Nvl\Support\Installation;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/** Describes the configuration resources supplied by one loaded package. @api */
final readonly class PackageInstallation implements InstallationContributor
{
    /** @var array<string, array{source: string, target: string, tag: string}> */
    public array $configuration;

    /**
     * Validate metadata without loading config files or resolving host adapters.
     *
     * @param  array<array-key, mixed>  $configuration
     */
    public function __construct(public string $package, array $configuration)
    {
        if (preg_match('~^nvl/[a-z][a-z0-9-]*$~D', $package) !== 1) {
            throw new InvalidArgumentException('Installation metadata requires a canonical Composer package name.');
        }
        $normalized = [];
        foreach ($configuration as $key => $entry) {
            if (! is_string($key) || ! is_array($entry)
                || preg_match('/^nvl-[a-z][a-z0-9-]*$/D', $key) !== 1
                || array_diff(array_keys($entry), ['source', 'target', 'tag']) !== []
                || count($entry) !== 3
                || ! is_string($entry['source'] ?? null) || ! is_string($entry['target'] ?? null) || ! is_string($entry['tag'] ?? null)
                || $entry['source'] === '' || $entry['target'] !== $key.'.php'
                || preg_match('/^nvl-[a-z][a-z0-9-]*-config$/D', $entry['tag']) !== 1) {
                throw new InvalidArgumentException('Installation config metadata must contain canonical source, target and tag entries.');
            }
            $normalized[$key] = ['source' => $entry['source'], 'target' => $entry['target'], 'tag' => $entry['tag']];
        }
        ksort($normalized);
        $this->configuration = $normalized;
    }

    /** Return this immutable metadata declaration. */
    public function installation(): self
    {
        return $this;
    }

    /**
     * Register metadata once without forcing installed but unloaded providers to boot.
     *
     * @param  array<array-key, mixed>  $configuration
     */
    public static function register(Container $container, string $package, array $configuration): void
    {
        $installation = new self($package, $configuration);
        $binding = 'nvl.installation.'.$package.'.'.hash('sha256', json_encode($installation->configuration, JSON_THROW_ON_ERROR));
        if (! $container->bound($binding)) {
            $container->instance($binding, $installation);
            $container->tag($binding, InstallationContributor::class);
        }
    }
}
