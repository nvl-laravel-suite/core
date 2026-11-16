<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Ownership\Contracts\OwnershipModelPersistence;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipStorageTables;
use Nvl\Support\Tests\Fixtures\MorphPivotOwnershipFixture;
use Nvl\Support\Tests\Fixtures\PivotOwnershipFixture;
use Nvl\Support\Tests\Fixtures\UnscopedOwnershipBase;

beforeEach(function (): void {
    Schema::create('ownership_persistence_pivots', static function (Blueprint $table): void {
        $table->uuid('id')->nullable()->unique();
        $table->unsignedBigInteger('left_id');
        $table->unsignedBigInteger('right_id');
        $table->string('subject_type');
        $table->unique(['left_id', 'right_id', 'subject_type'], 'ownership_persistence_pivots_identity');
    });
});

dataset('ownership persistence pivots', [
    'pivot with UUID' => [PivotOwnershipFixture::class, true],
    'pivot with composite keys' => [PivotOwnershipFixture::class, false],
    'morph pivot with UUID' => [MorphPivotOwnershipFixture::class, true],
    'morph pivot with composite keys' => [MorphPivotOwnershipFixture::class, false],
]);

/**
 * Hydrate a real persisted relationship with Laravel's native pivot metadata.
 *
 * @param  class-string<Pivot>  $class
 */
function persistedOwnershipPivot(string $class, bool $keyed): Pivot
{
    $attributes = ['left_id' => 7, 'right_id' => 11, 'subject_type' => 'product'];
    if ($keyed) {
        $attributes['id'] = '550e8400-e29b-41d4-a716-446655440000';
    }
    DB::table('ownership_persistence_pivots')->insert($attributes);
    DB::table('ownership_persistence_pivots')->insert(['left_id' => 7, 'right_id' => 12, 'subject_type' => 'product']);
    if (is_a($class, MorphPivot::class, true)) {
        DB::table('ownership_persistence_pivots')->insert(['left_id' => 7, 'right_id' => 11, 'subject_type' => 'other']);
    }
    $pivot = $class::fromRawAttributes(new UnscopedOwnershipBase, $attributes, 'ownership_persistence_pivots', true)
        ->setPivotKeys('left_id', 'right_id');
    if ($pivot instanceof MorphPivot) {
        $pivot->setMorphType('subject_type')->setMorphClass('product');
    }

    return $pivot;
}

it('preserves the integer deletion result and native relationship predicates', function (string $class, bool $keyed, string $method): void {
    $pivot = persistedOwnershipPivot($class, $keyed);

    expect($pivot->{$method}())->toBe(1)
        ->and($pivot->exists)->toBeFalse()
        ->and(DB::table($pivot->getTable())->where('right_id', 11)->where('subject_type', 'product')->exists())->toBeFalse()
        ->and(DB::table($pivot->getTable())->where('right_id', 12)->exists())->toBeTrue();
    if ($pivot instanceof MorphPivot) {
        expect(DB::table($pivot->getTable())->where('subject_type', 'other')->exists())->toBeTrue();
    }
})->with('ownership persistence pivots')->with(['delete', 'deleteQuietly', 'deleteOrFail']);

it('preserves integer zero when an observer cancels pivot deletion', function (string $class, bool $keyed): void {
    $pivot = persistedOwnershipPivot($class, $keyed);
    $class::deleting(static fn (): bool => false);

    expect($pivot->delete())->toBe(0)
        ->and($pivot->exists)->toBeTrue()
        ->and(DB::table($pivot->getTable())->where('right_id', 11)->where('subject_type', 'product')->exists())->toBeTrue();
})->with('ownership persistence pivots');

it('rejects persisted ownership partitioning before pivot observers and deletion', function (string $class, bool $keyed): void {
    $pivot = persistedOwnershipPivot($class, $keyed);
    Schema::create(OwnershipStorageTables::Markers, static function (Blueprint $table): void {
        $table->string('resource');
        $table->string('physical_table');
    });
    DB::table(OwnershipStorageTables::Markers)->insert(['resource' => 'fixture.pivots', 'physical_table' => $pivot->getTable()]);
    $observed = false;
    $class::deleting(function () use (&$observed): void {
        $observed = true;
    });

    expect(fn () => $pivot->delete())->toThrow(OwnershipSchemaNotReady::class)
        ->and($observed)->toBeFalse()
        ->and(DB::table($pivot->getTable())->where('right_id', 11)->where('subject_type', 'product')->exists())->toBeTrue();
})->with('ownership persistence pivots');

dataset('ownership each counters', [
    'incrementEach' => ['incrementEach', 1],
    'decrementEach' => ['decrementEach', -1],
    'incrementEachQuietly' => ['incrementEachQuietly', 1],
    'decrementEachQuietly' => ['decrementEachQuietly', -1],
]);

/** Persist one ordinary owned-model row with two counters and hydrate it without a guarded read. */
function persistedOwnershipCounterModel(): UnscopedOwnershipBase
{
    Schema::create('ownership_claimed_models', static function (Blueprint $table): void {
        $table->id();
        $table->integer('hits')->default(0);
        $table->integer('misses')->default(0);
    });

    $attributes = ['id' => 7, 'hits' => 5, 'misses' => 5];
    DB::table('ownership_claimed_models')->insert($attributes);

    return (new UnscopedOwnershipBase)->newFromBuilder($attributes);
}

it('rejects every Each counter before observers on protected storage', function (string $method): void {
    $model = persistedOwnershipCounterModel();
    Schema::create(OwnershipStorageTables::Markers, static function (Blueprint $table): void {
        $table->string('resource');
        $table->string('physical_table');
    });
    DB::table(OwnershipStorageTables::Markers)->insert(['resource' => 'fixture.claimed', 'physical_table' => $model->getTable()]);
    $observed = false;
    UnscopedOwnershipBase::updating(function () use (&$observed): void {
        $observed = true;
    });

    expect(fn () => $model->{$method}(['hits' => 2, 'misses' => 1]))->toThrow(OwnershipSchemaNotReady::class)
        ->and($observed)->toBeFalse()
        ->and((array) DB::table($model->getTable())->where('id', 7)->first(['hits', 'misses']))->toEqual(['hits' => 5, 'misses' => 5]);
})->with('ownership each counters');

it('keeps native Each counter results and observer vetoes on admitted storage', function (string $method, int $sign): void {
    $model = persistedOwnershipCounterModel();

    expect($model->{$method}(['hits' => 2, 'misses' => 1]))->toBe(1)
        ->and((array) DB::table($model->getTable())->where('id', 7)->first(['hits', 'misses']))->toEqual(['hits' => 5 + 2 * $sign, 'misses' => 5 + $sign]);

    UnscopedOwnershipBase::updating(static fn (): bool => false);
    $vetoed = $model->{$method}(['hits' => 2]);

    expect($vetoed)->toBe(str_ends_with($method, 'Quietly') ? 1 : false)
        ->and(DB::table($model->getTable())->where('id', 7)->value('hits'))->toEqual(str_ends_with($method, 'Quietly') ? 5 + 4 * $sign : 5 + 2 * $sign);
})->with('ownership each counters');

it('preserves nullable boolean deletion results for ordinary Eloquent models', function (): void {
    Schema::create('ownership_claimed_models', static function (Blueprint $table): void {
        $table->id();
    });
    $model = UnscopedOwnershipBase::query()->create(['id' => 7]);
    expect($model->delete())->toBeTrue()
        ->and((new UnscopedOwnershipBase)->delete())->toBeNull();

    $cancelled = UnscopedOwnershipBase::query()->create(['id' => 8]);
    UnscopedOwnershipBase::deleting(static fn (): bool => false);
    expect($cancelled->delete())->toBeFalse()
        ->and(DB::table($cancelled->getTable())->where('id', 8)->exists())->toBeTrue();
});

it('preserves a persistence adapter refusal without invoking native pivot deletion', function (string $class, bool $keyed): void {
    $pivot = persistedOwnershipPivot($class, $keyed);
    app()->instance(OwnershipModelPersistence::class, new class implements OwnershipModelPersistence
    {
        /** @param Closure(): bool $persist */
        public function save(Model $model, Closure $persist): bool
        {
            return $persist();
        }

        /** @param Closure(): (bool|null) $persist */
        public function delete(Model $model, Closure $persist): ?bool
        {
            return false;
        }
    });

    expect($pivot->delete())->toBeFalse()
        ->and($pivot->exists)->toBeTrue()
        ->and(DB::table($pivot->getTable())->where('right_id', 11)->where('subject_type', 'product')->exists())->toBeTrue();
})->with('ownership persistence pivots');

it('restores a queued pivot through its native queueable identity', function (string $class, bool $keyed): void {
    $pivot = persistedOwnershipPivot($class, $keyed);
    $id = $pivot->getQueueableId();
    expect(is_string($id) && str_contains($id, ':'))->toBe(! $keyed);

    $fresh = new $class;
    $fresh->setTable('ownership_persistence_pivots');
    $restored = $fresh->newQueryForRestoration($id)->get();
    expect($restored)->toHaveCount(1)
        ->and($restored[0]->getAttribute('right_id'))->toBe(11)
        ->and($restored[0]->getAttribute('subject_type'))->toBe('product');

    if (! $keyed) {
        expect(fn () => $fresh->newQueryForRestoration('left_id:7:right_id'))->toThrow(OwnershipViolation::class, 'malformed')
            ->and(fn () => $fresh->newQueryForRestoration([$id, 11]))->toThrow(OwnershipViolation::class, 'malformed');
        $collection = $fresh->newQueryForRestoration([$id, str_replace(':11', ':12', $id)])->orderBy('right_id')->get();
        expect($collection->pluck('right_id')->all())->toBe([11, 12])
            ->and($collection->pluck('subject_type')->unique()->all())->toBe(['product']);
    }
})->with('ownership persistence pivots');
