<?php

declare(strict_types=1);

namespace Nvl\Data\TypeScript;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelTypeScriptTransformer\LaravelData\ClassPropertyProcessors\DataClassPropertyProcessor;
use Spatie\LaravelTypeScriptTransformer\LaravelData\Transformers\DataClassTransformer;
use Spatie\TypeScriptTransformer\ClassPropertyProcessors\FixArrayLikeStructuresClassPropertyProcessor;
use UnexpectedValueException;

/**
 * Extracts Spatie Data properties with NVL optional and collection semantics.
 */
final class LaravelDataClassTransformer extends DataClassTransformer
{
    /**
     * Return the ordered property processors for NVL Data contracts.
     *
     * @return list<object>
     */
    protected function classPropertyProcessors(): array
    {
        return [
            new DataClassPropertyProcessor(
                $this->customLazyTypes,
                $this->nullableAsOptional,
            ),
            new OptionalPropertyProcessor,
            new DataCollectionOfPropertyProcessor,
            new FixArrayLikeStructuresClassPropertyProcessor(
                replaceArrays: true,
                arrayLikeClassesToReplace: [
                    Collection::class,
                    EloquentCollection::class,
                    DataCollection::class,
                    ...$this->customCollectionTypes(),
                ],
            ),
        ];
    }

    /**
     * Return validated custom collection class names.
     *
     * @return list<string>
     */
    private function customCollectionTypes(): array
    {
        $collections = [];

        foreach ($this->customDataCollections as $collection) {
            if (! is_string($collection)) {
                throw new UnexpectedValueException('Custom Data collection types must be class strings.');
            }

            $collections[] = $collection;
        }

        return $collections;
    }
}
