<?php

declare(strict_types=1);

namespace Nvl\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

/**
 * Shares owner identity without granting any package capability or changing legacy class-backed storage.
 */
final class OwnerRegistry
{
    /** @var array<string, class-string<Model>> */
    private array $owners = [];

    /** @var array<string, class-string<Model>> */
    private array $mapped = [];

    /** @var array<string, array{reference: string, replacement: string}> */
    private array $deprecated = [];

    /** Create the registry against the host's effective configuration. */
    public function __construct(private readonly Repository $configuration) {}

    /**
     * Register one canonical alias after validating every existing identity.
     *
     * @param  class-string<Model>  $model
     */
    public function register(string $alias, string $model): void
    {
        $this->all();
        $owners = $this->owners;
        $this->add($owners, $alias, $model);
        $this->validate($owners);
        $this->owners = $owners;
        $this->mapped[$alias] = $model;
        Relation::morphMap([$alias => $model], merge: true);
    }

    /**
     * Resolve one explicitly registered owner alias to its model class.
     *
     * @return class-string<Model>
     */
    public function model(string $alias): string
    {
        return $this->all()[$alias]
            ?? throw new InvalidArgumentException("Owner alias [{$alias}] is not registered in nvl-core.owners.");
    }

    /** Return the unique identity for one exact concrete owner model. */
    public function aliasFor(Model $model): string
    {
        $alias = array_search($model::class, $this->all(), true);

        return is_string($alias)
            ? $alias
            : throw new InvalidArgumentException('Owner model ['.$model::class.'] is not registered.');
    }

    /**
     * Resolve a canonical reference or a deprecated package class declaration.
     *
     * Legacy declarations only add a morph mapping when that package already did so.
     *
     * @return class-string<Model>
     */
    public function reference(
        string $reference,
        string $source,
        ?string $legacyAlias = null,
        bool $legacyMorphMap = false,
    ): string {
        $owners = $this->all();
        if (isset($owners[$reference])) {
            return $owners[$reference];
        }

        if (! is_a($reference, Model::class, true)) {
            if (class_exists($reference)) {
                throw new InvalidArgumentException("Owner reference [{$reference}] must be an Eloquent model class.");
            }

            return $this->model($reference);
        }

        $alias = array_search($reference, $owners, true);

        if ($legacyAlias !== null && isset($owners[$legacyAlias]) && $owners[$legacyAlias] !== $reference) {
            throw new InvalidArgumentException("Legacy owner [{$source}] conflicts with canonical alias [{$legacyAlias}].");
        }

        if (! is_string($alias) && $legacyAlias !== null) {
            $hostAlias = array_search($reference, Relation::morphMap(), true);
            $alias = is_string($hostAlias) ? $hostAlias : $legacyAlias;
            $this->add($owners, $alias, $reference);
            $this->validate($owners);
            $this->owners = $owners;
        }

        if ($legacyMorphMap && is_string($alias) && $legacyAlias !== $alias) {
            throw new InvalidArgumentException("Owner model [{$reference}] already uses morph alias [{$alias}] instead of [{$legacyAlias}].");
        }

        if ($legacyMorphMap && is_string($alias)) {
            $this->register($alias, $reference);
        }

        if ($legacyAlias !== null || $this->configuration->get($source) === $reference) {
            $this->deprecated[$source] = [
                'reference' => $reference,
                'replacement' => is_string($alias) ? "nvl-core.owners.{$alias}" : 'nvl-core.owners',
            ];
        }

        return $reference;
    }

    /**
     * Return all declared identities, validating a complete canonical map before merging it.
     *
     * @return array<string, class-string<Model>>
     */
    public function all(): array
    {
        $configured = $this->configuration->get('nvl-core.owners', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('nvl-core.owners must be an alias-to-model map.');
        }

        $owners = $this->owners;
        $mapped = $this->mapped;

        foreach ($configured as $alias => $model) {
            if (! is_string($alias) || ! is_string($model)) {
                throw new InvalidArgumentException('nvl-core.owners must map string aliases to model classes.');
            }

            $this->add($owners, $alias, $model);
            $mapped[$alias] = $owners[$alias];
        }

        $this->validate($owners);
        ksort($owners);
        $this->owners = $owners;
        $this->mapped = $mapped;

        if ($mapped !== []) {
            Relation::morphMap($mapped, merge: true);
        }

        return $owners;
    }

    /**
     * Return compatibility declarations once per source for consumer diagnostics.
     *
     * @return array<string, array{reference: string, replacement: string}>
     */
    public function deprecations(): array
    {
        $deprecated = $this->deprecated;
        ksort($deprecated);

        return $deprecated;
    }

    /**
     * Add a compatible model declaration to an uncommitted identity map.
     *
     * @param  array<string, class-string<Model>>  $owners
     */
    private function add(array &$owners, string $alias, string $model): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/D', $alias) !== 1
            || preg_match('/^[0-9]+$/D', $alias) === 1
            || ! is_a($model, Model::class, true)) {
            throw new InvalidArgumentException("Owner alias [{$alias}] or Eloquent model [{$model}] is invalid.");
        }

        if (isset($owners[$alias]) && $owners[$alias] !== $model) {
            throw new InvalidArgumentException("Owner alias [{$alias}] is already registered for [{$owners[$alias]}].");
        }

        $owners[$alias] = $model;
    }

    /**
     * Reject conflicting aliases before mutating Eloquent's global morph map.
     *
     * @param  array<string, class-string<Model>>  $owners
     */
    private function validate(array $owners): void
    {
        $models = [];

        foreach ($owners as $alias => $model) {
            if (isset($models[$model]) && $models[$model] !== $alias) {
                throw new InvalidArgumentException("Owner model [{$model}] already uses alias [{$models[$model]}].");
            }

            $models[$model] = $alias;

            foreach (Relation::morphMap() as $hostAlias => $hostModel) {
                if (($hostAlias === $alias && $hostModel !== $model)
                    || ($hostModel === $model && $hostAlias !== $alias)) {
                    throw new InvalidArgumentException("Owner [{$alias}:{$model}] conflicts with host morph alias [{$hostAlias}].");
                }
            }
        }
    }
}
