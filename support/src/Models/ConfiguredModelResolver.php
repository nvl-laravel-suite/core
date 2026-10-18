<?php

declare(strict_types=1);

namespace Nvl\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use ReflectionClass;

/** Validates advertised concrete model extensions without opening storage. */
final readonly class ConfiguredModelResolver
{
    /**
     * Resolve an exact configured concrete subclass of a supported base.
     *
     * @template TModel of Model
     *
     * @param  string  $configured  Deployment-owned model class
     * @param  class-string<TModel>  $base  Advertised package model
     * @return class-string<TModel>
     */
    public function resolve(string $configured, string $base): string
    {
        if (! is_a($configured, Model::class, true) || ! is_a($configured, $base, true)
            || ! (new ReflectionClass($configured))->isInstantiable()) {
            throw new OwnershipConfigurationInvalid('Configured models must be concrete subclasses of their advertised base.');
        }

        return $configured;
    }
}
