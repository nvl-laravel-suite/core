<?php

declare(strict_types=1);

namespace Nvl\Support\Models;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;

/**
 * Freezes one effective package model while retaining its canonical identity and persistence hooks.
 *
 * @internal
 *
 * @template TModel of Model
 */
final class ConfiguredModelRegistration
{
    /** @var class-string<TModel>|null */
    private ?string $definition = null;

    private bool $validated = false;

    /**
     * Retain deployment metadata and the package's specific ownership declaration rule.
     *
     * @param  class-string<TModel>  $base
     * @param  Closure(class-string<TModel>): void  $validate
     */
    public function __construct(
        private readonly Repository $configuration,
        private readonly OwnerRegistry $identities,
        private readonly string $path,
        private readonly string $base,
        private readonly string $resource,
        private readonly Closure $validate,
    ) {}

    /** Resolve and freeze the concrete class before compiling the package resource graph.
     * @return class-string<TModel>
     */
    public function prepare(): string
    {
        if ($this->definition !== null) {
            return $this->definition;
        }
        $configured = $this->configuration->get($this->path, $this->base);
        if (! is_string($configured)) {
            $name = class_basename($this->base);
            throw new OwnershipConfigurationInvalid($name.' model configuration must name a concrete '.$name.' subclass.');
        }
        $class = (new ConfiguredModelResolver)->resolve($configured, $this->base);
        ($this->validate)($class);
        $this->identities->registerReplacement($this->resource, $this->base, $class);

        return $this->definition = $class;
    }

    /** Validate capabilities after Laravel has installed Eloquent's dispatcher.
     * @return class-string<TModel>
     */
    public function modelClass(): string
    {
        $class = $this->prepare();
        if (! $this->validated) {
            (new ConfiguredModelResolver)->assertOwnershipCapabilities(new $class, new $this->base);
            $this->validated = true;
        }

        return $class;
    }
}
