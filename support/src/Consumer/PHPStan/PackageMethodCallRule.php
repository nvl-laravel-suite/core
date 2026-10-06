<?php

declare(strict_types=1);

namespace Nvl\Support\Consumer\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
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
        if ($node instanceof FuncCall && $node->name instanceof Name && strtolower($node->name->toString()) === 'json_encode' && isset($node->getArgs()[0])) {
            $errors = [];
            foreach ($this->policy->models($scope->getType($node->getArgs()[0]->value)) as $model) {
                array_push($errors, ...$this->policy->error('packageQuery', $model->class.'::jsonSerialize', $node, $scope));
            }

            return $errors;
        }
        if (! $node instanceof MethodCall || ! $node->name instanceof Identifier) {
            return [];
        }
        $method = $node->name->toString();
        $lower = strtolower($method);
        $type = $scope->getType($node->var);
        $errors = [];
        $models = $this->policy->models($type);
        foreach ($models as $model) {
            if ($this->policy->isContainer($type)) {
                if (in_array($lower, ['pluck', 'keyby', 'groupby'], true)) {
                    foreach (array_slice($node->getArgs(), 0, $lower === 'pluck' ? 2 : 1) as $argument) {
                        foreach ($scope->getType($argument->value)->getConstantStrings() as $field) {
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
