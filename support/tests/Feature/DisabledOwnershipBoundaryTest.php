<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Tests\Fixtures\OwnershipCapableModel;
use Nvl\Support\Tests\Fixtures\OwnershipDefinitionCompany;

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

it('does not treat enabled legacy tenancy as an unowned query escape', function (): void {
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('test.products', 'test', OwnershipCapableModel::class, OwnershipDefinition::unscoped()));
    config(['nvl-tenancy.enabled' => true]);
    expect(fn () => app(OwnershipBoundary::class)->query(OwnershipCapableModel::query(), 'test.products'))->toThrow(OwnershipViolation::class);
});
