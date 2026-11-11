<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Traits\ScopesOwnedBuilderWrites;

/**
 * Default builder of ownership-declaring models without a custom builder.
 *
 * @internal
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
final class OwnedEloquentBuilder extends Builder
{
    use ScopesOwnedBuilderWrites;
}
