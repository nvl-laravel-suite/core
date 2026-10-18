<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Tenancy\ValueObjects\TenantResourceDefinition;
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

it('delegates legacy query and attribute admission without asserting a transient persisted record', function (): void {
    DB::getSchemaBuilder()->create('ownership_claimed_models', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    DB::table('ownership_claimed_models')->insert(['name' => 'legacy']);
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('test.bridge', 'test', UnscopedOwnershipBase::class));
    $legacy = Mockery::mock(TenantBoundary::class);
    $legacy->shouldReceive('query')->once()->andReturnUsing(static fn ($query) => $query->where('name', 'legacy'));
    $legacy->shouldReceive('attributes')->once()->andReturn(['ownership_key' => 'platform']);
    $legacy->shouldReceive('key')->once()->with('test.bridge', 'cache')->andReturn('legacy:cache');
    $legacy->shouldReceive('assertRecord')->once()->andThrow(new RuntimeException('Persisted record guard still applies.'));
    app()->instance(TenantBoundary::class, $legacy);
    $boundary = app(OwnershipBoundary::class);
    $model = new UnscopedOwnershipBase;
    expect($boundary->query($model->newModelQuery(), 'test.bridge')->count())->toBe(1)
        ->and($boundary->attributes('test.bridge'))->toBe(['ownership_key' => 'platform'])
        ->and($boundary->key('test.bridge', 'cache'))->toBe('legacy:cache')
        ->and(fn () => $boundary->assertRecord($model, 'test.bridge'))->toThrow(RuntimeException::class, 'Persisted record guard still applies.');
});

it('checks legacy creation after native stamping and rolls back a forged quiet assignment', function (): void {
    DB::getSchemaBuilder()->create('ownership_claimed_models', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('ownership_key');
    });
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('test.bridge', 'test', UnscopedOwnershipBase::class, UnscopedOwnershipBase::ownershipDefinition()));
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('test.bridge', 'test', UnscopedOwnershipBase::class));
    $legacy = Mockery::mock(TenantBoundary::class);
    $legacy->shouldReceive('query')->andReturnUsing(static fn ($query) => $query);
    $legacy->shouldReceive('attributes')->andReturn(['ownership_key' => 'admitted']);
    $legacy->shouldReceive('assertRecord')->twice()->andReturnUsing(static function ($record): void {
        if (! $record->exists || $record->getRawOriginal('ownership_key') !== 'admitted') {
            throw new RuntimeException('Foreign persisted assignment.');
        }
    });
    app()->instance(TenantBoundary::class, $legacy);
    UnscopedOwnershipBase::creating(static fn ($record) => $record->forceFill(['ownership_key' => 'admitted']));
    $valid = new UnscopedOwnershipBase(['name' => 'native stamp']);
    expect($valid->save())->toBeTrue();
    $forged = new UnscopedOwnershipBase(['name' => 'forged', 'ownership_key' => 'foreign']);
    expect(fn () => $forged->saveQuietly())->toThrow(RuntimeException::class, 'Foreign persisted assignment.')
        ->and(DB::table('ownership_claimed_models')->pluck('name')->all())->toBe(['native stamp']);
});
