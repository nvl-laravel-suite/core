<?php

declare(strict_types=1);

namespace Nvl\Support\Consumer\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Unset_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\LineRuleError;
use PHPStan\Rules\Rule;

/**
 * Applies one safe-field policy to property and array syntax, including writes.
 *
 * @implements Rule<Node>
 */
final readonly class PackagePropertyRule implements Rule
{
    /** Share the installed consumer policy. */
    public function __construct(private ConsumerBoundaryPolicy $policy) {}

    /** Observe native property reads and assignment expressions. */
    public function getNodeType(): string
    {
        return Node::class;
    }

    /** Deny model mutation and reads outside the exact declared field set. */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->policy->excludes($scope)) {
            return [];
        }
        if ($node instanceof Unset_) {
            $errors = [];
            foreach ($node->vars as $variable) {
                array_push($errors, ...$this->access($variable, true, $node, $scope));
            }

            return $errors;
        }
        if ($node instanceof Assign || $node instanceof AssignRef || $node instanceof AssignOp || $node instanceof PreInc || $node instanceof PostInc || $node instanceof PreDec || $node instanceof PostDec) {
            return $this->access($node->var, true, $node, $scope);
        }

        return $this->access($node, false, $node, $scope);
    }

    /** Apply the same field policy at the root of nested property/offset writes.
     * @return list<IdentifierRuleError&LineRuleError>
     */
    private function access(Node $fetch, bool $write, Node $node, Scope $scope): array
    {
        if ($write) {
            while ($fetch instanceof ArrayDimFetch && ($fetch->var instanceof ArrayDimFetch || $fetch->var instanceof PropertyFetch)) {
                $fetch = $fetch->var;
            }
        }
        if ($fetch instanceof PropertyFetch && $fetch->name instanceof Identifier) {
            if (! $write && $scope->isInExpressionAssign($fetch)) {
                return [];
            }
            $type = $scope->getType($fetch->var);
            $fields = [$fetch->name->toString()];
        } elseif ($fetch instanceof ArrayDimFetch && $fetch->dim !== null) {
            if (! $write && $scope->isInExpressionAssign($fetch)) {
                return [];
            }
            $type = $scope->getType($fetch->var);
            $fields = array_map(static fn ($field): string => $field->getValue(), $scope->getType($fetch->dim)->getConstantStrings());
        } else {
            return [];
        }
        $errors = [];
        $models = $this->policy->models($type, false);
        foreach ($models as $model) {
            foreach ($fields as $field) {
                if ($write || ! in_array($field, $model->readableFields, true)) {
                    array_push($errors, ...$this->policy->error($write ? 'packageWrite' : 'packageQuery', $model->class.'::$'.$field, $node, $scope));
                }
            }
        }

        if ($models === [] && $fetch instanceof PropertyFetch) {
            foreach ($type->getObjectClassNames() as $class) {
                foreach ($fields as $field) {
                    if (! $this->policy->allowsMember($class, $field, 'property')) {
                        array_push($errors, ...$this->policy->error('internalApi', $class.'::$'.$field, $node, $scope));
                    }
                }
            }
        }

        return $errors;
    }
}
