<?php

declare(strict_types=1);

namespace Nvl\Support\Consumer\PHPStan;

use Illuminate\Support\Collection;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\LineRuleError;
use PHPStan\Rules\Rule;

/**
 * Keeps inferred package model handles restricted through calls and serialization.
 *
 * @implements Rule<Node>
 */
final readonly class PackageMethodCallRule implements Rule
{
    /** Share the installed consumer policy. */
    public function __construct(private ConsumerBoundaryPolicy $policy) {}

    /** Include global JSON serialization as well as method calls. */
    public function getNodeType(): string
    {
        return Node::class;
    }

    /** Classify receiver operations without manually tracking variables. */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->policy->excludes($scope)) {
            return [];
        }
        if ($node instanceof FuncCall && $node->name instanceof Name && strtolower($node->name->toString()) === 'json_encode'
            && ($type = $this->policy->argumentType($node, 'value', 0, $scope)) !== null) {
            $errors = [];
            foreach ($this->policy->models($type) as $model) {
                array_push($errors, ...$this->policy->error('packageQuery', $model->class.'::jsonSerialize', $node, $scope));
            }

            return $errors;
        }
        if (! $node instanceof MethodCall) {
            return [];
        }
        $methods = $node->name instanceof Identifier ? [$node->name->toString()] : array_map(static fn ($name): string => $name->getValue(), $scope->getType($node->name)->getConstantStrings());
        $errors = [];
        foreach ($methods as $method) {
            array_push($errors, ...$this->methodCall($node, $scope, $method));
        }

        return $errors;
    }

    /**
     * Classify one statically known callable name, including constant-string invocation syntax.
     *
     * @return list<IdentifierRuleError&LineRuleError>
     */
    private function methodCall(MethodCall $node, Scope $scope, string $method): array
    {
        $lower = strtolower($method);
        $type = $scope->getType($node->var);
        $errors = [];
        $models = $this->policy->models($type);
        foreach ($models as $model) {
            if ($this->policy->isContainer($type)) {
                if (in_array($lower, ['pluck', 'keyby', 'groupby'], true)) {
                    $reflection = $this->policy->reflection(Collection::class);
                    foreach ($type->getObjectClassReflections() as $receiver) {
                        if ($receiver->hasNativeMethod($method)) {
                            $reflection = $receiver;
                            break;
                        }
                    }
                    $parameters = $reflection?->getNativeMethod($method)->getVariants()[0]->getParameters() ?? [];
                    foreach (array_slice($parameters, 0, $lower === 'pluck' ? 2 : 1) as $position => $parameter) {
                        $argument = $this->policy->argumentType($node, $parameter->getName(), $position, $scope);
                        foreach ($argument?->getConstantStrings() ?? [] as $field) {
                            if (! in_array($field->getValue(), $model->readableFields, true)) {
                                array_push($errors, ...$this->policy->error('packageQuery', $model->class.'::$'.$field->getValue(), $node, $scope));
                            }
                        }
                    }

                    continue;
                }
                if (! preg_match('/^(?:load|fresh|toQuery|toArray|toJson|jsonSerialize|toPrettyJson|withRelationshipAutoloading)/i', $method)) {
                    continue;
                }
            } elseif (! $this->policy->isQuery($type) && in_array($lower, array_map('strtolower', $model->identityMethods), true)) {
                continue;
            }
            array_push($errors, ...$this->policy->error($this->policy->writes($method) ? 'packageWrite' : 'packageQuery', $model->class.'::'.$this->policy->methodName($model->class, $method), $node, $scope));
        }
        if ($models !== []) {
            return $errors;
        }
        foreach ($type->getObjectClassNames() as $class) {
            if (! $this->policy->allowsMember($class, $method, 'method')) {
                array_push($errors, ...$this->policy->error('internalApi', $class.'::'.$method, $node, $scope));
            }
        }

        return $errors;
    }
}
