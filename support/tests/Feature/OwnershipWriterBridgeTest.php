<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
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
