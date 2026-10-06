<?php

declare(strict_types=1);

namespace Nvl\Support\Consumer\PHPStan;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
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
        if ($this->policy->excludes($scope) || (! $node instanceof MethodCall && ! $node instanceof StaticCall && ! $node instanceof PropertyFetch) || ! $node->name instanceof Identifier) {
            return [];
        }
        $type = $node instanceof StaticCall ? ($node->class instanceof Name ? $scope->resolveTypeByName($node->class) : $scope->getType($node->class)) : $scope->getType($node->var);
        if ($this->policy->models($type) !== []) {
            return [];
        }
        $errors = [];
        $member = $node->name->toString();
        foreach ($this->hostModels($type) as $host) {
            $relations = $this->relations($host);
            if (isset($relations[$member])) {
                array_push($errors, ...$this->policy->error('capabilityRelation', $relations[$member].'::'.($node instanceof PropertyFetch ? '$' : '').$member, $node, $scope));

                continue;
            }
            if ($node instanceof PropertyFetch) {
                continue;
            }
            if (! preg_match('/^(?:with|withCount|withExists|withAggregate|withSum|withAvg|withMin|withMax|has|orHas|doesntHave|orDoesntHave|whereHas|orWhereHas|whereDoesntHave|orWhereDoesntHave|withWhereHas|whereRelation|orWhereRelation|whereMorphRelation|orWhereMorphRelation|whereDoesntHaveRelation|orWhereDoesntHaveRelation|load|loadMissing|loadCount|loadAggregate|loadSum|loadAvg|loadMin|loadMax|loadExists|loadMorph|loadMorphCount)$/i', $member)) {
                continue;
            }
            foreach ($node->getArgs() as $argument) {
                $argumentType = $scope->getType($argument->value);
                $paths = $argumentType->getConstantStrings();
                foreach ($argumentType->getConstantArrays() as $array) {
                    foreach ($array->getKeyTypes() as $key) {
                        array_push($paths, ...$key->getConstantStrings());
                    }
                    foreach ($array->getValueTypes() as $value) {
                        array_push($paths, ...$value->getConstantStrings());
                    }
                }
                foreach ($paths as $path) {
                    $relation = $this->relationPath($host, $path->getValue());
                    if ($relation !== null) {
                        array_push($errors, ...$this->policy->error('capabilityRelation', $relation, $node, $scope));
                    }
                }
            }
        }

        return $errors;
    }

    /** Follow statically declared host relation return types to the first owned capability. */
    private function relationPath(ClassReflection $host, string $path): ?string
    {
        $path = preg_split('/:|\\s+as\\s+/i', $path)[0] ?? $path;
        $segments = explode('.', $path);
        $segment = array_shift($segments);
        $relations = $this->relations($host);
        if (isset($relations[$segment])) {
            return $relations[$segment].'::'.$segment;
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
     * @return list<ClassReflection>
     */
    private function hostModels(Type $type): array
    {
        $models = [];
        foreach ($this->policy->constituents($type) as $part) {
            foreach ([Builder::class => $part->getTemplateType(Builder::class, 'TModel'), Collection::class => $part->getTemplateType(Collection::class, 'TValue')] as $ancestor => $receiver) {
                if ((new ObjectType($ancestor))->isSuperTypeOf($part)->yes()) {
                    array_push($models, ...$this->hostModels($receiver));
                }
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
     * Retain each relation's owning trait and literal vocabulary declarations.
     *
     * @return array<string,string>
     */
    private function relations(ClassReflection $host): array
    {
        $relations = [];
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
