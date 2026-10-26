<?php

declare(strict_types=1);

namespace Nvl\Data\TypeScript;

use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\PhpNodes\PhpNamedTypeNode;
use Spatie\TypeScriptTransformer\PhpNodes\PhpPropertyNode;
use Spatie\TypeScriptTransformer\PhpNodes\PhpTypeNode;
use Spatie\TypeScriptTransformer\PhpNodes\PhpUnionTypeNode;
use Spatie\TypeScriptTransformer\Transformers\ClassPropertyProcessors\ClassPropertyProcessor;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;

/**
 * Marks every Spatie Optional-backed DTO property as optional in TypeScript.
 */
final class OptionalPropertyProcessor implements ClassPropertyProcessor
{
    /**
     * Mark a property optional when its declared PHP type contains Optional.
     */
    public function execute(
        PhpPropertyNode $phpPropertyNode,
        mixed $annotation,
        TypeScriptProperty $property,
    ): TypeScriptProperty {
        if ($this->containsOptional($phpPropertyNode->getType())) {
            $property->isOptional = true;
        }

        return $property;
    }

    /**
     * Determine whether a PHP type node contains Spatie Optional.
     */
    private function containsOptional(?PhpTypeNode $type): bool
    {
        if ($type instanceof PhpNamedTypeNode) {
            return $type->getName() === Optional::class;
        }

        if ($type instanceof PhpUnionTypeNode) {
            foreach ($type->getTypes() as $subType) {
                if ($subType instanceof PhpTypeNode && $this->containsOptional($subType)) {
                    return true;
                }
            }
        }

        return false;
    }
}
