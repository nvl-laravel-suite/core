<?php

declare(strict_types=1);

namespace Nvl\Support\Consumer\PHPStan;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\HigherOrderCollectionProxy;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

/**
 * Forbids package capability traversal while preserving public authorized host scopes.
 *
 * @implements Rule<Node>
 */
final readonly class HostCapabilityRelationRule implements Rule
{
    /** Share the installed consumer policy. */
    public function __construct(private ConsumerBoundaryPolicy $policy) {}

    /** Inspect host property, method and static builder operations. */
    public function getNodeType(): string
    {
        return Node::class;
    }

    /** Resolve capability traits and constant relationship arguments without host model boot. */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->policy->excludes($scope)) {
            return [];
        }
        if ($node instanceof FuncCall && $node->name instanceof Name && strtolower($node->name->toString()) === 'json_encode'
            && ($type = $this->policy->argumentType($node, 'value', 0, $scope)) !== null) {
            $members = ['jsonSerialize'];
        } elseif ($node instanceof ArrayDimFetch && $node->dim !== null) {
            $type = $scope->getType($node->var);
            $members = array_map(static fn ($name): string => $name->getValue(), $scope->getType($node->dim)->getConstantStrings());
        } elseif ($node instanceof MethodCall || $node instanceof StaticCall || $node instanceof PropertyFetch) {
            $type = $node instanceof StaticCall ? ($node->class instanceof Name ? $scope->resolveTypeByName($node->class) : $scope->getType($node->class)) : $scope->getType($node->var);
            $members = $node->name instanceof Identifier ? [$node->name->toString()] : array_map(static fn ($name): string => $name->getValue(), $scope->getType($node->name)->getConstantStrings());
        } else {
            return [];
        }
        $errors = [];
        foreach ($this->hostModels($type, ! $node instanceof ArrayDimFetch) as $host) {
            $relations = $this->relations($host);
            foreach ($members as $member) {
                $selectedMember = $this->nativeAlias($host, $member);
                $relationName = $this->matchedName(array_keys($relations), $selectedMember);
                if ($relationName !== null) {
                    array_push($errors, ...$this->policy->error('capabilityRelation', $relations[$relationName].'::'.($node instanceof PropertyFetch || $node instanceof ArrayDimFetch ? '$' : '').$relationName, $node, $scope));

                    continue;
                }
                if ($node instanceof PropertyFetch || $node instanceof ArrayDimFetch) {
                    continue;
                }
                $extension = $this->policy->hostExtension($host);
                if ($extension !== null) {
                    $protected = $this->matchedName($extension->capabilityMethods, $selectedMember, true);
                    if ($protected !== null) {
                        array_push($errors, ...$this->policy->error('capabilityRelation', $extension->class.'::'.$protected, $node, $scope));

                        continue;
                    }
                }
                if (! preg_match('/^(?:with|without|withOnly|withCount|withExists|withAggregate|withSum|withAvg|withMin|withMax|has|orHas|doesntHave|orDoesntHave|whereHas|orWhereHas|whereDoesntHave|orWhereDoesntHave|withWhereHas|withWhereRelation|whereRelation|orWhereRelation|whereMorphRelation|orWhereMorphRelation|whereDoesntHaveRelation|orWhereDoesntHaveRelation|whereAttachedTo|orWhereAttachedTo|load|loadMissing|loadCount|loadAggregate|loadSum|loadAvg|loadMin|loadMax|loadExists|loadMorph|loadMorphCount|getRelation|getRelationValue|getRelationshipFromMethod|getAttribute|getAttributeValue|offsetGet|setRelation|unsetRelation|setAttribute|offsetSet|offsetUnset|pluck|keyBy|groupBy|value|only|except)$/i', $selectedMember)) {
                    continue;
                }
                $limit = preg_match('/^(?:with|without|withOnly|load|loadMissing|only|except)$/i', $selectedMember) === 1 ? null : (strcasecmp($selectedMember, 'pluck') === 0 ? 2 : 1);
                $offset = preg_match('/^(?:whereAttachedTo|orWhereAttachedTo)$/i', $selectedMember) === 1 ? 1 : 0;
                $arguments = $this->arguments($node, $host, $member);
                if ($offset === 1 && isset($arguments[0]) && (! isset($arguments[1]) || $scope->getType($arguments[1]->value)->isNull()->yes())) {
                    foreach ($this->relatedModels($scope->getType($arguments[0]->value)) as $related) {
                        $relation = $this->relationPath($host, Str::plural(Str::camel(class_basename($related->getName()))));
                        if ($relation !== null) {
                            array_push($errors, ...$this->policy->error('capabilityRelation', $relation, $node, $scope));
                        }
                    }
                }
                foreach ($arguments as $position => $argument) {
                    if ($position < $offset || ($limit !== null && $position >= $offset + $limit)) {
                        continue;
                    }
                    foreach ($this->relationArguments($scope->getType($argument->value)) as $path) {
                        $relation = $this->relationPath($host, $path);
                        if ($relation !== null) {
                            array_push($errors, ...$this->policy->error('capabilityRelation', $relation, $node, $scope));
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Retain native parameter positions when PHP source uses named arguments out of order.
     *
     * @return array<int, Arg>
     */
    private function arguments(CallLike $call, ClassReflection $host, string $method): array
    {
        $parameters = [];
        foreach ([$host->getName(), Builder::class, Collection::class, Relation::class] as $class) {
            $reflection = $this->policy->reflection($class);
            if ($reflection !== null && $reflection->hasNativeMethod($method)) {
                $variant = $reflection->getNativeMethod($method)->getVariants()[0];
                foreach ($variant->getParameters() as $position => $parameter) {
                    $parameters[$parameter->getName()] = $position;
                }
                break;
            }
        }
        $arguments = [];
        foreach (array_values($call->getArgs()) as $position => $argument) {
            $index = $argument->name !== null ? ($parameters[$argument->name->toString()] ?? $position) : $position;
            $arguments[$index] = $argument;
        }
        ksort($arguments);

        return $arguments;
    }

    /**
     * Resolve the related model used by Laravel's implicit attached-to relationship name.
     *
     * @param  array<string, true>  $seen
     * @return list<ClassReflection>
     */
    private function relatedModels(Type $type, array $seen = []): array
    {
        $models = [];
        foreach ($this->policy->constituents($type) as $part) {
            $key = $part->describe(VerbosityLevel::precise());
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ((new ObjectType(Collection::class))->isSuperTypeOf($part)->yes()) {
                array_push($models, ...$this->relatedModels($part->getTemplateType(Collection::class, 'TValue'), $seen));
            }
            foreach ($part->getObjectClassReflections() as $reflection) {
                if ($reflection->isSubclassOf(Model::class)) {
                    $models[] = $reflection;
                }
            }
        }

        return $models;
    }

    /**
     * Preserve nested eager-load array paths while ignoring nonliteral callback values.
     *
     * @return list<string>
     */
    private function relationArguments(Type $type, string $prefix = ''): array
    {
        $paths = [];
        foreach ($type->getConstantStrings() as $value) {
            $paths[] = $prefix.$value->getValue();
        }
        foreach ($type->getConstantArrays() as $array) {
            foreach ($array->getKeyTypes() as $index => $key) {
                $value = $array->getValueTypes()[$index];
                $keys = $key->getConstantStrings();
                if ($keys === []) {
                    array_push($paths, ...$this->relationArguments($value, $prefix));

                    continue;
                }
                foreach ($keys as $name) {
                    $path = $prefix.$name->getValue();
                    $paths[] = $path;
                    array_push($paths, ...$this->relationArguments($value, $path.'.'));
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /** Match public method spelling and Laravel's declared scope alias without widening capabilities.
     * @param  list<string>  $names
     */
    private function matchedName(array $names, string $member, bool $scopes = false): ?string
    {
        foreach ($names as $name) {
            if (strcasecmp($name, $member) === 0 || ($scopes && str_starts_with($name, 'scope') && strcasecmp(substr($name, 5), $member) === 0)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Preserve the original protected member behind a host-authored native trait alias.
     *
     * @param  array<string, true>  $seen
     */
    private function nativeAlias(ClassReflection $host, string $member, array $seen = []): string
    {
        $native = $host->getNativeReflection();
        if ($native->hasMethod($member) && ! $native->getMethod($member)->getBetterReflection()->getDeclaringClass()->isTrait()) {
            return $member;
        }
        $key = strtolower($host->getName().'::'.$member);
        if (isset($seen[$key])) {
            return $member;
        }
        $seen[$key] = true;
        foreach (array_merge($host->getAncestors(), $host->getTraits(true)) as $ancestor) {
            foreach ($ancestor->getNativeReflection()->getTraitAliases() as $alias => $source) {
                if (strcasecmp($alias, $member) === 0) {
                    [$trait, $original] = explode('::', $source, 2);
                    $reflection = $this->policy->reflection($trait);

                    return $reflection !== null ? $this->nativeAlias($reflection, $original, $seen) : $original;
                }
            }
        }

        return $member;
    }

    /** Follow statically declared host relation return types to the first owned capability. */
    private function relationPath(ClassReflection $host, string $path): ?string
    {
        $path = preg_split('/:|\\s+as\\s+/i', $path)[0] ?? $path;
        $segments = explode('.', $path);
        $segment = array_shift($segments);
        $relations = $this->relations($host);
        $name = $this->matchedName(array_keys($relations), $this->nativeAlias($host, $segment));
        if ($name !== null) {
            return $relations[$name].'::'.$name;
        }
        if ($segments === [] || ! $host->hasNativeMethod($segment)) {
            return null;
        }
        foreach ($host->getNativeMethod($segment)->getVariants() as $variant) {
            $related = $variant->getReturnType()->getTemplateType(Relation::class, 'TRelatedModel');
            foreach ($related->getObjectClassReflections() as $reflection) {
                $relation = $this->relationPath($reflection, implode('.', $segments));
                if ($relation !== null) {
                    return $relation;
                }
            }
        }

        return null;
    }

    /**
     * Extract host models from direct and named generic receiver types.
     *
     * @param  array<string, true>  $seen
     * @return list<ClassReflection>
     */
    private function hostModels(Type $type, bool $containers = true, array $seen = []): array
    {
        $models = [];
        foreach ($this->policy->constituents($type) as $part) {
            $key = $part->describe(VerbosityLevel::precise());
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($containers && $part->isArray()->yes()) {
                array_push($models, ...$this->hostModels($part->getIterableValueType(), true, $seen));
            }
            foreach ([
                Builder::class => $part->getTemplateType(Builder::class, 'TModel'),
                Relation::class => $part->getTemplateType(Relation::class, 'TRelatedModel'),
                Factory::class => $part->getTemplateType(Factory::class, 'TModel'),
                Collection::class => $part->getTemplateType(Collection::class, 'TValue'),
                HigherOrderCollectionProxy::class => $part->getTemplateType(HigherOrderCollectionProxy::class, 'TValue'),
                Paginator::class => $part->getTemplateType(Paginator::class, 'TValue'),
                CursorPaginator::class => $part->getTemplateType(CursorPaginator::class, 'TValue'),
            ] as $ancestor => $receiver) {
                if ($containers && (new ObjectType($ancestor))->isSuperTypeOf($part)->yes()) {
                    array_push($models, ...$this->hostModels($receiver, true, $seen));
                }
            }
            foreach ($part->getObjectClassReflections() as $reflection) {
                if ($reflection->isSubclassOf(Model::class) && $this->policy->models(new ObjectType($reflection->getName()), false) === []) {
                    $models[] = $reflection;
                }
            }
        }

        return $models;
    }

    /**
     * Retain each relation's owning trait and literal vocabulary declarations.
     *
     * @return array<string,string>
     */
    private function relations(ClassReflection $host): array
    {
        $relations = [];
        $extension = $this->policy->hostExtension($host);
        if ($extension !== null) {
            foreach ($extension->capabilityRelations as $name) {
                $relations[$name] = $extension->class;
            }
        }
        foreach ($host->getTraits(true) as $trait) {
            foreach ($this->policy->capabilityRelations($trait->getName()) as $name) {
                $relations[$name] = $trait->getName();
            }
            if ($trait->getName() !== 'Nvl\\Taxonomy\\Concerns\\HasTaxonomies') {
                continue;
            }
            $relations['hasTerm'] = $trait->getName();
            $native = $host->getNativeReflection();
            if (! $native->hasProperty('taxonomies')) {
                continue;
            }
            $property = $native->getProperty('taxonomies');
            if (! $property->hasDefaultValue()) {
                continue;
            }
            $default = $property->getDefaultValueExpression();
            if (! $default instanceof Array_) {
                continue;
            }
            foreach ($default->items as $item) {
                if ($item->value instanceof String_) {
                    $relations[Str::plural($item->value->value)] = $trait->getName();
                }
            }
        }

        return $relations;
    }
}
