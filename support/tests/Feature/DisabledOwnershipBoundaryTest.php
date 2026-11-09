<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\OwnershipStorageTables;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Tests\Fixtures\OwnershipCapableModel;
use Nvl\Support\Tests\Fixtures\OwnershipDefinitionCompany;
use Nvl\Support\Tests\Fixtures\UnscopedOwnershipBase;

it('retains ordinary unadopted queries and operation identities without the runtime', function (): void {
    $model = new OwnershipCapableModel;
    Schema::create($model->getTable(), static function (Blueprint $table): void {
        $table->id();
    });
    OwnershipCapableModel::query()->insert(['id' => 7]);
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('test.products', 'test', OwnershipCapableModel::class, OwnershipDefinition::unscoped()));
    $boundary = app(OwnershipBoundary::class);
    expect($boundary->query(OwnershipCapableModel::query(), 'test.products')->count())->toBe(1)
        ->and($boundary->attributes('test.products'))->toBe([])
        ->and($boundary->key('test.products', 'same'))->toBe('same');
});

it('denies a declared owner or enabled runtime configuration when its enforcing runtime is absent', function (): void {
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('test.products', 'test', OwnershipCapableModel::class, OwnershipDefinition::column(OwnershipDefinitionCompany::class, 'company_id')));
    expect(fn () => app(OwnershipBoundary::class)->query(OwnershipCapableModel::query(), 'test.products'))->toThrow(OwnershipViolation::class);
});

it('does not treat enabled ownership without its runtime as an unowned query escape', function (): void {
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('test.products', 'test', OwnershipCapableModel::class, OwnershipDefinition::unscoped()));
    config(['nvl-ownership.enabled' => true]);
    expect(fn () => app(OwnershipBoundary::class)->query(OwnershipCapableModel::query(), 'test.products'))->toThrow(OwnershipViolation::class);
});

it('uses one fresh storage probe per registered unowned model mutation', function (): void {
    Schema::create('ownership_claimed_models', static function (Blueprint $table): void {
        $table->id();
    });
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('test.unowned', 'test', UnscopedOwnershipBase::class, OwnershipDefinition::unscoped()));
    $connection = DB::connection();
    $connection->enableQueryLog();
    try {
        $model = UnscopedOwnershipBase::query()->create(['id' => 7]);
        expect($connection->getQueryLog())->toHaveCount(2);

        $connection->flushQueryLog();
        $model->forceFill(['id' => 8])->save();
        expect($connection->getQueryLog())->toHaveCount(2);

        $connection->flushQueryLog();
        expect($model->delete())->toBeTrue()
            ->and($connection->getQueryLog())->toHaveCount(2);
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
});

it('detects newly persisted adoption on the next mutation of a retained unowned model', function (): void {
    Schema::create('ownership_claimed_models', static function (Blueprint $table): void {
        $table->id();
    });
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('test.unowned', 'test', UnscopedOwnershipBase::class, OwnershipDefinition::unscoped()));
    $model = UnscopedOwnershipBase::query()->create(['id' => 7]);
    Schema::create(OwnershipStorageTables::Markers, static function (Blueprint $table): void {
        $table->string('resource');
        $table->string('physical_table');
    });
    DB::table(OwnershipStorageTables::Markers)->insert(['resource' => 'test.unowned', 'physical_table' => $model->getTable()]);

    expect(fn () => $model->saveQuietly())->toThrow(OwnershipSchemaNotReady::class)
        ->and(fn () => $model->deleteQuietly())->toThrow(OwnershipSchemaNotReady::class)
        ->and(DB::table($model->getTable())->where('id', 7)->exists())->toBeTrue();
});

it('rejects a registered new model whose native query redirects canonical storage', function (): void {
    foreach (['ownership_claimed_models', 'ownership_other_models'] as $table) {
        Schema::create($table, static function (Blueprint $table): void {
            $table->id();
        });
    }
    $model = new class extends UnscopedOwnershipBase
    {
        /** @return Builder<static> */
        public function newModelQuery(): Builder
        {
            return parent::newModelQuery()->from('ownership_other_models');
        }
    };
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('test.redirected', 'test', $model::class, OwnershipDefinition::unscoped()));

    expect(fn () => $model->forceFill(['id' => 7])->save())->toThrow(OwnershipViolation::class)
        ->and(DB::table('ownership_claimed_models')->count())->toBe(0)
        ->and(DB::table('ownership_other_models')->count())->toBe(0);
});
