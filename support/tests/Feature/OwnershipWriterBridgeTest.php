<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tests\Fixtures\OwnershipDefinitionCompany;
use Nvl\Support\Tests\Fixtures\UnscopedOwnershipBase;

it('creates unowned package models without requiring the optional runtime', function (): void {
    DB::getSchemaBuilder()->create('ownership_claimed_models', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('test.writer', 'test', UnscopedOwnershipBase::class, UnscopedOwnershipBase::ownershipDefinition()));
    $model = app(OwnershipWriter::class)->create(UnscopedOwnershipBase::class, fn (array $ownership): UnscopedOwnershipBase => UnscopedOwnershipBase::query()->create([...$ownership, 'name' => 'native']));
    expect($model)->toBeInstanceOf(UnscopedOwnershipBase::class)->and($model->name)->toBe('native');
    expect(fn () => app(OwnershipWriter::class)->create(UnscopedOwnershipBase::class, function (array $ownership): UnscopedOwnershipBase {
        UnscopedOwnershipBase::query()->create([...$ownership, 'name' => 'rolled-back']);
        throw new RuntimeException('Package persistence failed.');
    }))->toThrow(RuntimeException::class);
    expect(DB::table('ownership_claimed_models')->pluck('name')->all())->toBe(['native']);
});

it('admits native unowned queries without consulting the retired boundary', function (): void {
    DB::getSchemaBuilder()->create('ownership_claimed_models', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    DB::table('ownership_claimed_models')->insert(['name' => 'native']);
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('test.writer', 'test', UnscopedOwnershipBase::class, UnscopedOwnershipBase::ownershipDefinition()));
    app()->beforeResolving(TenantBoundary::class, static function (): never {
        throw new LogicException('Native unowned access resolved retired authority.');
    });
    $boundary = app(OwnershipBoundary::class);
    $model = new UnscopedOwnershipBase;
    expect($boundary->query($model->newModelQuery()->where('name', 'native'), 'test.writer')->count())->toBe(1)
        ->and($boundary->attributes('test.writer'))->toBe([])
        ->and($boundary->key('test.writer', 'cache'))->toBe('cache');
    $boundary->assertRecord($model, 'test.writer');
});

it('denies declared ownership before writer callbacks and quiet model persistence', function (): void {
    DB::getSchemaBuilder()->create('ownership_claimed_models', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('company_id');
    });
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('test.writer', 'test', UnscopedOwnershipBase::class, OwnershipDefinition::column(OwnershipDefinitionCompany::class, 'company_id')));
    $called = false;
    expect(fn () => app(OwnershipWriter::class)->create(UnscopedOwnershipBase::class, function (array $ownership) use (&$called): UnscopedOwnershipBase {
        $called = true;

        return UnscopedOwnershipBase::query()->create([...$ownership, 'name' => 'forged', 'company_id' => 'foreign']);
    }))->toThrow(OwnershipViolation::class);
    $forged = new UnscopedOwnershipBase(['name' => 'forged', 'company_id' => 'foreign']);
    expect(fn () => $forged->saveQuietly())->toThrow(OwnershipViolation::class)
        ->and($called)->toBeFalse()
        ->and(DB::table('ownership_claimed_models')->count())->toBe(0);
});
