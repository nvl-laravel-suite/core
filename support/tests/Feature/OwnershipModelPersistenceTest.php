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
