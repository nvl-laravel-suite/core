<?php

declare(strict_types=1);

namespace Nvl\Support\Consumer\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\LineRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\FileTypeMapper;

/**
 * Checks resolved native and PHPDoc consumption independently of PHPStan's configured level.
 *
 * @implements Rule<Node>
 */
final readonly class SymbolUsageRule implements Rule
{
    /** Resolve documentation using the same lexical context as PHPStan. */
    public function __construct(private ConsumerBoundaryPolicy $policy, private FileTypeMapper $fileTypeMapper, private TypeStringResolver $typeStringResolver) {}

    /** Observe native syntax with explicit lexical declaration context. */
    public function getNodeType(): string
    {
        return Node::class;
    }

    /** Inspect supported class consumption, defaults and exact non-model members. */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->policy->excludes($scope)) {
            return [];
        }
        $names = [];
        $errors = [];
        $original = $node;
        if ($node instanceof Use_ || $node instanceof GroupUse) {
            foreach ($node->uses as $use) {
                if (($use->type === Use_::TYPE_UNKNOWN ? $node->type : $use->type) !== Use_::TYPE_NORMAL) {
                    continue;
                }
                $class = ($node instanceof GroupUse ? $node->prefix->toString().'\\' : '').$use->name->toString();
                array_push($errors, ...$this->classErrors($class, $use, $scope));
            }

            return $errors;
        }
        if ($node instanceof ClassLike) {
            $original = $node;
            if ($original instanceof Class_ && $original->extends !== null) {
                $names[] = $original->extends;
            }
            if ($original instanceof Class_ || $original instanceof Enum_) {
                array_push($names, ...$original->implements);
            } elseif ($original instanceof Interface_) {
                array_push($names, ...$original->extends);
            }
        } elseif ($node instanceof ClassMethod || $node instanceof Function_) {
            $original = $node;
            foreach ($original->getParams() as $parameter) {
                array_push($names, ...$this->nativeNames($parameter->type));
            }
            array_push($names, ...$this->nativeNames($original->getReturnType()));
        } elseif ($node instanceof Closure || $node instanceof ArrowFunction) {
            foreach ($node->params as $parameter) {
                array_push($names, ...$this->nativeNames($parameter->type));
            }
            array_push($names, ...$this->nativeNames($node->returnType));
        } elseif ($node instanceof Property) {
            $original = $node;
            array_push($names, ...$this->nativeNames($node->type));
        } elseif ($node instanceof TraitUse) {
            $names = $node->traits;
        } elseif ($node instanceof Catch_) {
            $names = $node->types;
        } elseif ($node instanceof Attribute) {
            $names[] = $node->name;
        } elseif ($node instanceof Instanceof_ || $node instanceof New_ || $node instanceof ClassConstFetch || $node instanceof StaticPropertyFetch) {
            if ($node->class instanceof Name) {
                $names[] = $node->class;
                $class = $scope->resolveName($node->class);
                $selected = $this->policy->symbol($class);
                if ($selected !== null && $this->policy->models($scope->resolveTypeByName($node->class), false) === []) {
                    $member = $node instanceof New_ ? '__construct' : (($node instanceof ClassConstFetch || $node instanceof StaticPropertyFetch) && $node->name instanceof Identifier ? $node->name->toString() : null);
                    $kind = $node instanceof New_ ? 'method' : ($node instanceof StaticPropertyFetch ? 'property' : 'constant');
                    if ($member !== null && strtolower($member) !== 'class' && ! $this->policy->allowsMember($class, $member, $kind)) {
                        array_push($errors, ...$this->policy->error('internalApi', $class.'::'.($kind === 'property' ? '$' : '').$member, $node, $scope));
                    }
                }
            } elseif (! $node->class instanceof Class_) {
                foreach ($scope->getType($node->class)->getObjectClassNames() as $class) {
                    array_push($errors, ...$this->classErrors($class, $node, $scope));
                }
            }
        }
        foreach ($names as $name) {
            array_push($errors, ...$this->classErrors($scope->resolveName($name), $name, $scope));
        }
        if ($node instanceof ClassLike || $node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Property || $node instanceof Closure || $node instanceof ArrowFunction || $node instanceof Expression || $node instanceof TraitUse) {
            $comment = $original->getDocComment();
            if ($comment !== null) {
                $className = $scope->getClassReflection()?->getName();
                if ($node instanceof ClassLike && $node->name !== null) {
                    $className = $node->namespacedName?->toString() ?? ltrim(($scope->getNamespace() ?? '').'\\'.$node->name->toString(), '\\');
                }
                $functionName = $scope->getFunction()?->getName();
                if ($node instanceof Function_) {
                    $functionName = $node->namespacedName?->toString() ?? $node->name->toString();
                } elseif ($node instanceof ClassMethod) {
                    $functionName = $node->name->toString();
                }
                $doc = $this->fileTypeMapper->getResolvedPhpDoc($scope->getFile(), $className, $scope->getTraitReflection()?->getName(), $functionName, $comment->getText());
                $types = [];
                foreach ([$doc->getParamTags(), $doc->getParamOutTags(), $doc->getParamClosureThisTags(), $doc->getVarTags(), $doc->getExtendsTags(), $doc->getImplementsTags(), $doc->getUsesTags(), $doc->getMixinTags(), $doc->getRequireExtendsTags(), $doc->getRequireImplementsTags(), $doc->getSealedTags(), $doc->getAssertTags()] as $tags) {
                    foreach ($tags as $tag) {
                        $types[] = $tag->getType();
                    }
                }
                foreach ([$doc->getReturnTag(), $doc->getThrowsTag(), $doc->getSelfOutTag()] as $tag) {
                    if ($tag !== null) {
                        $types[] = $tag->getType();
                    }
                }
                foreach ($doc->getTemplateTags() as $tag) {
                    $types[] = $tag->getBound();
                    if ($tag->getDefault() !== null) {
                        $types[] = $tag->getDefault();
                    }
                }
                foreach ($doc->getPropertyTags() as $tag) {
                    foreach ([$tag->getReadableType(), $tag->getWritableType()] as $type) {
                        if ($type !== null) {
                            $types[] = $type;
                        }
                    }
                }
                foreach ($doc->getMethodTags() as $tag) {
                    $types[] = $tag->getReturnType();
                    foreach ($tag->getParameters() as $parameter) {
                        $types[] = $parameter->getType();
                    }
                }
                foreach ($doc->getTypeAliasTags() as $tag) {
                    $types[] = $this->typeStringResolver->resolve($tag->getAliasName(), $doc->getNullableNameScope());
                }
                foreach ($doc->getTypeAliasImportTags() as $tag) {
                    array_push($errors, ...$this->classErrors($tag->getImportedFrom(), $original, $scope));
                    $types[] = $this->typeStringResolver->resolve($tag->getImportedAs() ?? $tag->getImportedAlias(), $doc->getNullableNameScope());
                }
                foreach ($types as $type) {
                    foreach ($type->getReferencedClasses() as $class) {
                        array_push($errors, ...$this->classErrors($class, $original, $scope));
                    }
                }
            }
        }
        $unique = [];
        foreach ($errors as $error) {
            $unique[$error->getLine().'|'.$error->getMessage()] = $error;
        }

        return array_values($unique);
    }

    /**
     * Walk native nullable, union and intersection declarations.
     *
     * @return list<Name>
     */
    private function nativeNames(?Node $node): array
    {
        if ($node instanceof Name) {
            return [$node];
        }
        if ($node instanceof Node\NullableType) {
            return $this->nativeNames($node->type);
        }
        if ($node instanceof Node\UnionType || $node instanceof Node\IntersectionType) {
            $names = [];
            foreach ($node->types as $type) {
                array_push($names, ...$this->nativeNames($type));
            }

            return $names;
        }

        return [];
    }

    /**
     * Reject only source-owned class references lacking a selected declaration.
     *
     * @return list<IdentifierRuleError&LineRuleError>
     */
    private function classErrors(string $class, Node $node, Scope $scope): array
    {
        return $this->policy->ownedClass($class) !== null && $this->policy->symbol($class) === null
            ? $this->policy->error('internalApi', $class, $node, $scope)
            : [];
    }
}
