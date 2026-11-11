<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnedEloquentBuilder;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Tests\Fixtures\OwnedOwnershipReplacement;
use Nvl\Support\Tests\Fixtures\OwnershipCapableModel;
use Nvl\Support\Tests\Fixtures\OwnershipUnrelatedModel;
use Nvl\Support\Tests\Fixtures\UnscopedOwnershipBase;

beforeEach(function (): void {
    Schema::create('ownership_claimed_models', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('company_id')->nullable();
        $table->string('name')->nullable();
    });
    DB::table('ownership_claimed_models')->insert([['id' => 1, 'name' => 'first'], ['id' => 2, 'name' => 'second']]);
});

it('gives ownership-declaring models the write-scoping builder and leaves ordinary models native', function (): void {
    expect((new OwnershipCapableModel)->newQuery())->toBeInstanceOf(OwnedEloquentBuilder::class)
        ->and((new OwnershipUnrelatedModel)->newQuery()::class)->toBe(Builder::class);
});

it('keeps scope-free builder writes native for unowned storage without a runtime', function (): void {
    expect(UnscopedOwnershipBase::query()->where('id', 1)->forceDelete())->toBe(1)
        ->and(UnscopedOwnershipBase::query()->updateOrInsert(['id' => 2], ['name' => 'renamed']))->toBeTrue()
        ->and(UnscopedOwnershipBase::query()->updateOrInsert(['id' => 3], ['name' => 'inserted']))->toBeTrue()
        ->and(DB::table('ownership_claimed_models')->orderBy('id')->pluck('name', 'id')->all())->toBe([2 => 'renamed', 3 => 'inserted']);
});

it('refuses scope-free builder writes over storage claimed by an owned replacement', function (): void {
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('test.claimed', 'test', OwnedOwnershipReplacement::class, OwnedOwnershipReplacement::ownershipDefinition()));

    expect(fn () => UnscopedOwnershipBase::query()->forceDelete())->toThrow(OwnershipViolation::class)
        ->and(fn () => UnscopedOwnershipBase::query()->updateOrInsert(['id' => 2], ['name' => 'taken']))->toThrow(OwnershipViolation::class)
        ->and(fn () => OwnedOwnershipReplacement::query()->forceDelete())->toThrow(OwnershipViolation::class)
        ->and(DB::table('ownership_claimed_models')->orderBy('id')->pluck('name', 'id')->all())->toBe([1 => 'first', 2 => 'second']);
});

it('fails closed when an ownership model would receive the plain framework builder', function (): void {
    $model = new class extends UnscopedOwnershipBase
    {
        protected function resolveCustomBuilderClass(): false
        {
            return false;
        }
    };

    expect(fn () => $model->newQuery())->toThrow(OwnershipConfigurationInvalid::class);
});
