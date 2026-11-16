<?php

declare(strict_types=1);

namespace Nvl\Support\Contracts;

use Illuminate\Database\Query\Builder;

/**
 * Applies a substring predicate using the host's case and text-folding rules.
 *
 * @api
 */
interface TextMatcher
{
    /**
     * Append a predicate for a plain or qualified column. Null columns never match.
     * With wildcards enabled, `%` matches any string and `_` matches one character of the compared text.
     * The escape character `!` is always interpreted literally in the supplied value.
     *
     * Use `and` or `or` as the predicate conjunction.
     *
     * Returns the authoritative builder, which may be a different instance. Callers must use the return value.
     */
    public function contains(Builder $query, string $column, string $value, bool $negated = false, bool $wildcards = false, string $boolean = 'and'): Builder;
}
