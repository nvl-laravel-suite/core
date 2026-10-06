<?php

declare(strict_types=1);

namespace Nvl\Support\Consumer\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Type\ObjectType;
use Spatie\LaravelData\Contracts\BaseData;

/**
 * Prevents static package queries and unsupported entry points.
 *
 * @implements Rule<StaticCall>
 */
final readonly class PackageStaticCallRule implements Rule
{
    /** Share the installed consumer policy. */
    public function __construct(private ConsumerBoundaryPolicy $policy) {}

    /** Select static invocation expressions. */
    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /** Check model and exact public member semantics. */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->policy->excludes($scope) || ! $node->name instanceof Identifier) {
            return [];
        }
        $method = $node->name->toString();
        $type = $node->class instanceof Name ? $scope->resolveTypeByName($node->class) : $scope->getType($node->class);
        $errors = [];
        foreach ($this->policy->models($type) as $model) {
            array_push($errors, ...$this->policy->error($this->policy->writes($method) ? 'packageWrite' : 'packageQuery', $model->class.'::'.$this->policy->methodName($model->class, $method), $node, $scope));
        }
        if ($errors !== [] || $this->policy->models($type) !== []) {
            return $errors;
        }
        if (in_array(strtolower($method), ['from', 'collect'], true) && (new ObjectType(BaseData::class))->isSuperTypeOf($type)->yes()) {
            foreach ($node->getArgs() as $argument) {
                foreach ($this->policy->models($scope->getType($argument->value)) as $model) {
                    array_push($errors, ...$this->policy->error('packageQuery', $model->class.'::'.$this->policy->methodName($model->class, $method), $node, $scope));
                }
            }
            if ($errors !== []) {
                return $errors;
            }
        }
        foreach ($type->getObjectClassNames() as $class) {
            if (! $this->policy->allowsMember($class, $method, 'method')) {
                array_push($errors, ...$this->policy->error('internalApi', $class.'::'.$method, $node, $scope));
            }
        }

        return $errors;
    }
}
