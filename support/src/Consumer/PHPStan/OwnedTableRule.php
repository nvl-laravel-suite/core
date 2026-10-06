<?php

declare(strict_types=1);

namespace Nvl\Support\Consumer\PHPStan;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Type\ObjectType;

/**
 * Rejects exact installed raw tables on real database and schema receivers.
 *
 * @implements Rule<Node>
 */
final readonly class OwnedTableRule implements Rule
{
    /** Share the installed consumer policy. */
    public function __construct(private ConsumerBoundaryPolicy $policy) {}

    /** Inspect facade and inferred connection/builder calls. */
    public function getNodeType(): string
    {
        return Node::class;
    }

    /** Check constant table arguments without claiming dynamic SQL coverage. */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->policy->excludes($scope) || (! $node instanceof StaticCall && ! $node instanceof MethodCall) || ! $node->name instanceof Identifier) {
            return [];
        }
        $method = strtolower($node->name->toString());
        if (! in_array($method, ['table', 'from', 'join', 'leftjoin', 'rightjoin', 'crossjoin', 'joinwhere', 'leftjoinwhere', 'rightjoinwhere', 'create', 'drop', 'dropifexists', 'hastable', 'hascolumn', 'hascolumns', 'rename', 'getcolumns', 'getindexes', 'getforeignkeys'], true)) {
            return [];
        }
        $type = $node instanceof MethodCall ? $scope->getType($node->var) : ($node->class instanceof Name ? $scope->resolveTypeByName($node->class) : $scope->getType($node->class));
        $database = false;
        foreach ([DB::class, Schema::class, ConnectionInterface::class, Builder::class, EloquentBuilder::class, SchemaBuilder::class] as $class) {
            if ((new ObjectType($class))->isSuperTypeOf($type)->yes()) {
                $database = true;
                break;
            }
        }
        if (! $database) {
            return [];
        }
        $errors = [];
        foreach (array_slice($node->getArgs(), 0, $method === 'rename' ? 2 : 1) as $argument) {
            foreach ($scope->getType($argument->value)->getConstantStrings() as $constant) {
                $table = $this->policy->table($constant->getValue());
                if ($table !== null) {
                    array_push($errors, ...$this->policy->error('ownedTable', $table, $node, $scope));
                }
            }
        }

        return $errors;
    }
}
