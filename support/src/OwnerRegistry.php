<?php

declare(strict_types=1);

namespace Nvl\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Nvl\Support\Globals\GlobalNames;

/** Declares owner capabilities while retaining Laravel's host-owned morph identity. */
final class OwnerRegistry
{
    /** @var array<string, array{model: class-string<Model>, alias: string|null}> */
    private array $declarations = [];

    /** @var array<string, string> */
    private array $diagnostics = [];

    /** Retain the host's effective capability declarations. */
    public function __construct(private readonly Repository $configuration) {}

    /**
     * Accept a historical registration without installing a host morph mapping.
     *
     * @param  class-string<Model>  $model  Owner class
     */
    public function register(string $alias, string $model): void
    {
        $this->validateAlias($alias);
        $this->declare($model, "registration.{$alias}", $alias);
    }

    /**
     * Resolve a class or one-major historical alias to its declared model.
     *
     * @return class-string<Model>
     */
    public function model(string $reference): string
    {
        $owners = $this->all();
        if (is_a($reference, Model::class, true) && in_array($reference, $owners, true)) {
            return $reference;
        }
        if (isset($owners[$reference])) {
            return $owners[$reference];
        }
        foreach ($this->inputs() as $declaration) {
            if ($declaration['alias'] === $reference) {
                return $declaration['model'];
            }
        }

        throw new InvalidArgumentException("Owner [{$reference}] is not declared in nvl-core.owners.");
    }

    /** Return the same identity native Laravel relations use for a declared model. */
    public function aliasFor(Model $model): string
    {
        if (! in_array($model::class, $this->all(), true)) {
            throw new InvalidArgumentException('Owner model ['.$model::class.'] is not registered.');
        }

        return $model->getMorphClass();
    }

    /**
     * Declare a package capability using a model class or a historical reference.
     *
     * @return class-string<Model>
     */
    public function reference(string $reference, string $source, ?string $legacyAlias = null, bool $legacyMorphMap = false): string
    {
        if (! is_a($reference, Model::class, true)) {
            $owners = $this->all();
            $legacyModel = null;
            foreach ($this->inputs() as $input) {
                if ($input['alias'] === $reference) {
                    $legacyModel = $input['model'];
                    break;
                }
            }
            if ($legacyModel !== null || isset($owners[$reference])) {
                $reference = $legacyModel ?? $owners[$reference];
            } elseif (class_exists($reference)) {
                throw new InvalidArgumentException("Owner reference [{$reference}] must be an Eloquent model class.");
            } else {
                $reference = $this->model($reference);
            }
        }
        if ($legacyAlias === $reference) {
            $legacyAlias = null;
        }
        if ($legacyAlias !== null) {
            $this->validateAlias($legacyAlias);
        }
        $this->declare($reference, $source, $legacyAlias);

        return $reference;
    }

    /**
     * Return capabilities keyed by each model's current native morph identity.
     *
     * @return array<string, class-string<Model>>
     */
    public function all(): array
    {
        $owners = [];
        $this->diagnostics = [];
        foreach ($this->inputs() as $source => $declaration) {
            $model = $declaration['model'];
            $identity = (new $model)->getMorphClass();
            if (isset($owners[$identity]) && $owners[$identity] !== $model) {
                throw new InvalidArgumentException("Owner morph identity [{$identity}] resolves to multiple models.");
            }
            $owners[$identity] = $model;
            if ($declaration['alias'] !== null && $declaration['alias'] !== $identity) {
                $this->diagnostics[$source] = "Owner declaration [{$source}] uses [{$declaration['alias']}] but Laravel uses [{$identity}] for [{$model}]. Add the desired mapping in the host's morph map with data reconciliation, or remove the legacy alias.";
            }
        }
        ksort($owners);

        return $owners;
    }

    /** @return array<string, string> Legacy identity mismatches for explicit Doctor checks */
    public function errors(): array
    {
        $this->all();

        return $this->diagnostics;
    }

    /**
     * Matching historical declarations are accepted silently for this major.
     *
     * @return array<string, array{reference: string, replacement: string}>
     */
    public function deprecations(): array
    {
        return [];
    }

    /**
     * Register package-owned identities only, preserving all host-authored mappings.
     *
     * @param  string  $model  Package-owned class validated at this public boundary
     * @param  list<string>  $legacy  Read-compatible package aliases
     */
    public function registerPackage(string $alias, string $model, array $legacy = []): void
    {
        $this->validateAlias($alias);
        $this->validateModel($model);
        if (! str_starts_with($model, 'Nvl\\')) {
            throw new InvalidArgumentException('Only NVL-owned models may install a package morph mapping.');
        }
        $map = Relation::morphMap();
        if (isset($map[$alias]) && $map[$alias] !== $model) {
            throw new InvalidArgumentException("Package morph alias [{$alias}] is occupied by a host model.");
        }
        $readableLegacy = [];
        $names = new GlobalNames($this->configuration);
        foreach ($legacy as $candidate) {
            $this->validateAlias($candidate);
            if (isset($map[$candidate]) && $map[$candidate] !== $model) {
                $names->reserve('core', 'morph.legacy', $candidate,
                    static fn (string $name): bool => isset($map[$name]),
                    static function (string $name) use ($model): void {
                        Relation::morphMap([$name => $model]);
                    },
                );

                continue;
            }
            $readableLegacy[] = $candidate;
        }
        foreach ($map as $hostAlias => $hostModel) {
            if ($hostModel === $model && $hostAlias !== $alias && ! in_array($hostAlias, $legacy, true)) {
                $this->declare($model, 'package.'.$alias, null);

                return;
            }
        }
        $owned = [$alias => $model];
        foreach ($readableLegacy as $candidate) {
            $owned[$candidate] = $model;
        }
        Relation::morphMap($owned + $map, false);
        $this->declare($model, 'package.'.$alias, null);
    }

    /** @return array<string, array{model: class-string<Model>, alias: string|null}> */
    private function inputs(): array
    {
        $configured = $this->configuration->get('nvl-core.owners', []);
        if (! is_array($configured)) {
            throw new InvalidArgumentException('nvl-core.owners must be a list of Eloquent model classes.');
        }
        $inputs = $this->declarations;
        foreach ($configured as $alias => $model) {
            if (is_int($alias) && ! array_is_list($configured)) {
                throw new InvalidArgumentException('nvl-core.owners must use a sequential class list or string compatibility aliases.');
            }
            $this->validateModel($model);
            if (is_string($alias)) {
                $this->validateAlias($alias);
            }
            $inputs['nvl-core.owners.'.$alias] = ['model' => $model, 'alias' => is_string($alias) ? $alias : null];
        }

        return $inputs;
    }

    /** Validate a model received through configuration or a public registration. */
    private function declare(string $model, string $source, ?string $alias): void
    {
        $this->validateModel($model);
        if (isset($this->declarations[$source]) && $this->declarations[$source]['model'] !== $model) {
            throw new InvalidArgumentException("Owner declaration [{$source}] is already registered for another model.");
        }
        $this->declarations[$source] = ['model' => $model, 'alias' => $alias];
    }

    /** @phpstan-assert class-string<Model> $model */
    private function validateModel(mixed $model): void
    {
        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            throw new InvalidArgumentException('Owner declarations must reference Eloquent model classes.');
        }
    }

    /** Reject malformed compatibility labels without manufacturing a morph identity. */
    private function validateAlias(string $alias): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/D', $alias) !== 1 || ctype_digit($alias)) {
            throw new InvalidArgumentException("Owner alias [{$alias}] is invalid.");
        }
    }
}
