<?php

declare(strict_types=1);

namespace Nvl\Data\TypeScript;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\PhpNodes\PhpPropertyNode;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\Transformers\ClassPropertyProcessors\ClassPropertyProcessor;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptArray;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnion;

/**
 * Preserves DataCollectionOf item types when a PHP collection is transformed.
 */
final class DataCollectionOfPropertyProcessor implements ClassPropertyProcessor
{
    /**
     * Replace a collection-like property with an array of its attributed Data item.
     */
    public function execute(
        PhpPropertyNode $phpPropertyNode,
        mixed $annotation,
        TypeScriptProperty $property,
    ): TypeScriptProperty {
        $attribute = $phpPropertyNode->getAttributes(DataCollectionOf::class)[0] ?? null;

        if ($attribute === null) {
            return $property;
        }

        $collectionItemClass = $attribute->getArgument('class');

        if (! is_string($collectionItemClass)) {
            return $property;
        }

        $property->type = $this->replaceCollectionType(
            $property->type,
            new TypeScriptArray([
                new TypeScriptReference(new ClassStringReference($collectionItemClass)),
            ]),
        );

        return $property;
    }

    /**
     * Replace collection members while retaining null and other union members.
     */
    private function replaceCollectionType(
        TypeScriptNode $type,
        TypeScriptArray $collectionType,
    ): TypeScriptNode {
        if ($type instanceof TypeScriptUnion) {
            foreach ($type->types as $index => $subType) {
                if ($this->isCollectionType($subType) || $subType instanceof TypeScriptArray) {
                    $type->types[$index] = $collectionType;
                }
            }

            $type->deduplicateNodes();

            return $type;
        }

        if ($this->isCollectionType($type) || $type instanceof TypeScriptArray) {
            return $collectionType;
        }

        return $type;
    }

    /**
     * Determine whether a TypeScript node references a supported collection type.
     */
    private function isCollectionType(TypeScriptNode $type): bool
    {
        if (! $type instanceof TypeScriptReference) {
            return false;
        }

        if (! $type->reference instanceof ClassStringReference) {
            return false;
        }

        return in_array($type->reference->classString, [
            Collection::class,
            EloquentCollection::class,
            DataCollection::class,
        ], true);
    }
}
