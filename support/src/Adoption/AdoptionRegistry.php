<?php

declare(strict_types=1);

namespace Nvl\Support\Adoption;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Nvl\Support\Enums\SystemActor;

/**
 * Maps package names to adapter classes and builds a fresh adapter for every invocation.
 *
 * @api
 */
final class AdoptionRegistry
{
    /** @var array<string, class-string<AdoptionAdapter>> */
    private array $adapters = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Register or replace the adapter class for a package; call from the package provider's boot method.
     *
     * @param  class-string<AdoptionAdapter>  $adapter
     */
    public function register(string $package, string $adapter): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $package) !== 1) {
            throw new InvalidArgumentException("Adoption package [{$package}] must be a lowercase package slug.");
        }
        if (! is_subclass_of($adapter, AdoptionAdapter::class)) {
            throw new InvalidArgumentException("Adoption adapter [{$adapter}] must extend ".AdoptionAdapter::class.'.');
        }
        $this->adapters[$package] = $adapter;
    }

    /**
     * Whether a package registered an adapter.
     */
    public function has(string $package): bool
    {
        return isset($this->adapters[$package]);
    }

    /**
     * @return array<string, class-string<AdoptionAdapter>>
     */
    public function adapters(): array
    {
        $adapters = $this->adapters;
        ksort($adapters);

        return $adapters;
    }

    /**
     * Build a new adapter with the explicit actor and normalised options.
     *
     * @param  array<array-key, mixed>  $options
     */
    public function make(string $package, Authenticatable|SystemActor $actor, array $options = []): AdoptionAdapter
    {
        $class = $this->adapters[$package] ?? throw new AdoptionException("No adoption adapter is registered for package [{$package}].");
        $adapter = $this->container->make($class, ['actor' => $actor, 'options' => self::normalizeOptions($options)]);
        if (! $adapter instanceof AdoptionAdapter) {
            throw new AdoptionException("Adoption adapter for package [{$package}] did not resolve to an adapter.");
        }

        return $adapter;
    }

    /**
     * Require string keys at the top level and sort every map so equal options hash equally.
     *
     * @param  array<array-key, mixed>  $options
     * @return array<string, mixed>
     */
    public static function normalizeOptions(array $options): array
    {
        $normalized = [];
        foreach ($options as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw new InvalidArgumentException('Adoption options must be a JSON object with named keys.');
            }
            $normalized[$key] = is_array($value) ? self::sorted($value) : $value;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        $value = array_map(static fn (mixed $item): mixed => is_array($item) ? self::sorted($item) : $item, $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
