<?php

declare(strict_types=1);

use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Tests\Fixtures\OwnedOwnershipReplacement;
use Nvl\Support\Tests\Fixtures\OwnershipCapableModel;
use Nvl\Support\Tests\Fixtures\OwnershipStorageProbe;
use Nvl\Support\Tests\Fixtures\OwnershipUnrelatedModel;
use Nvl\Support\Tests\Fixtures\PackageOwner;
use Nvl\Support\Tests\Fixtures\UnscopedOwnershipBase;

it('exposes only disabled context and immutable metadata when the runtime is absent', function (): void {
    expect(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Disabled)
        ->and(fn () => app(OwnershipContext::class)->requireOwner())->toThrow(OwnershipViolation::class)
        ->and(app(OwnershipResourceRegistry::class))->toBe(app(OwnershipResourceRegistry::class));
});

it('registers exact configured models and freezes complete immutable metadata', function (): void {
    $registry = new OwnershipResourceRegistry;
    $resource = new OwnershipResourceDefinition('test.products', 'test', OwnershipCapableModel::class, OwnershipDefinition::unscoped());
    $registry->register($resource);
    $registry->register($resource);
    $registry->freeze();
    expect($registry->get('test.products'))->toBe($resource)
        ->and($registry->forModel(new OwnershipCapableModel))->toBe($resource)
        ->and(fn () => $registry->register(new OwnershipResourceDefinition('test.other', 'test', PackageOwner::class, OwnershipDefinition::unscoped())))
        ->toThrow(OwnershipConfigurationInvalid::class);
});

it('rejects conflicting models and incomplete canonical parent graphs', function (): void {
    $registry = new OwnershipResourceRegistry;
    $registry->register(new OwnershipResourceDefinition('test.products', 'test', OwnershipCapableModel::class, OwnershipDefinition::unscoped()));
    expect(fn () => $registry->register(new OwnershipResourceDefinition('test.other', 'test', OwnershipCapableModel::class, OwnershipDefinition::unscoped())))
        ->toThrow(OwnershipConfigurationInvalid::class);
    $registry = new OwnershipResourceRegistry;
    $registry->register(new OwnershipResourceDefinition('test.children', 'test', OwnershipCapableModel::class, OwnershipDefinition::inherited('parent'), parentResource: 'test.missing'));
    expect(fn () => $registry->freeze())->toThrow(OwnershipConfigurationInvalid::class);
});

it('rejects a cycle even when its parents are registered later', function (): void {
    $registry = new OwnershipResourceRegistry;
    $registry->register(new OwnershipResourceDefinition('test.a', 'test', OwnershipCapableModel::class, OwnershipDefinition::inherited('parent'), parentResource: 'test.b'));
    expect(fn () => $registry->register(new OwnershipResourceDefinition('test.b', 'test', PackageOwner::class, OwnershipDefinition::inherited('parent'), parentResource: 'test.a')))
        ->toThrow(OwnershipConfigurationInvalid::class);
});

it('rejects self and multi-hop cycles closed in any order while admitting shared ancestors', function (): void {
    $definition = static fn (string $key, string $model, array $parents): OwnershipResourceDefinition => new OwnershipResourceDefinition($key, 'test', $model, OwnershipDefinition::inherited('subject'), parentResources: $parents);
    $registry = new OwnershipResourceRegistry;
    expect(fn () => $registry->register($definition('test.self', OwnershipCapableModel::class, ['test.self'])))->toThrow(OwnershipConfigurationInvalid::class);
    $registry->register($definition('test.a', OwnershipCapableModel::class, ['test.b', 'test.c']));
    $registry->register($definition('test.c', PackageOwner::class, ['test.d']));
    $registry->register($definition('test.b', OwnershipUnrelatedModel::class, ['test.d']));
    expect(fn () => $registry->register($definition('test.d', UnscopedOwnershipBase::class, ['test.a'])))->toThrow(OwnershipConfigurationInvalid::class)
        ->and(array_keys($registry->all()))->toBe(['test.a', 'test.c', 'test.b']);
    $registry->register(new OwnershipResourceDefinition('test.d', 'test', UnscopedOwnershipBase::class, OwnershipDefinition::unscoped()));
    $registry->freeze();
    $registry->freeze();
    expect(array_keys($registry->all()))->toBe(['test.a', 'test.c', 'test.b', 'test.d']);
});

it('retains explicit polymorphic parent closure without a second native morph map', function (): void {
    $registry = new OwnershipResourceRegistry;
    $registry->register(new OwnershipResourceDefinition('test.children', 'test', OwnershipCapableModel::class, OwnershipDefinition::inherited('subject'), parentResources: ['test.parents']));
    expect(fn () => $registry->freeze())->toThrow(OwnershipConfigurationInvalid::class);
    $registry->register(new OwnershipResourceDefinition('test.parents', 'test', PackageOwner::class, OwnershipDefinition::unscoped()));
    $registry->freeze();
    expect($registry->get('test.children')->parents())->toBe(['test.parents']);
});

it('denies the unscoped base model when an owned replacement claims its storage before adoption', function (): void {
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('test.claimed', 'test', OwnedOwnershipReplacement::class, OwnedOwnershipReplacement::ownershipDefinition()));
    expect(fn () => UnscopedOwnershipBase::query()->count())->toThrow(OwnershipViolation::class)
        ->and(fn () => (new UnscopedOwnershipBase)->saveQuietly())->toThrow(OwnershipViolation::class)
        ->and(fn () => (new UnscopedOwnershipBase)->newQueryForRestoration(1)->first())->toThrow(OwnershipViolation::class);
});

it('constructs each owned canonical model once across repeated unclaimed admissions', function (): void {
    new OwnershipStorageProbe;
    OwnershipStorageProbe::resetProbe();
    $registry = new OwnershipResourceRegistry;
    $registry->register(new OwnershipResourceDefinition('test.unscoped', 'test', OwnershipCapableModel::class, OwnershipDefinition::unscoped()));
    $registry->register(new OwnershipResourceDefinition('test.probe', 'test', OwnershipStorageProbe::class, OwnershipStorageProbe::ownershipDefinition()));
    for ($admission = 0; $admission < 5; $admission++) {
        $registry->assertUnclaimed(new OwnershipUnrelatedModel);
    }
    expect(OwnershipStorageProbe::$constructed)->toBe(1);
});

it('reads owned canonical storage on every unclaimed admission instead of caching it', function (): void {
    OwnershipStorageProbe::resetProbe();
    config(['database.connections.unclaimed_secondary' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    $registry = new OwnershipResourceRegistry;
    $registry->register(new OwnershipResourceDefinition('test.probe', 'test', OwnershipStorageProbe::class, OwnershipStorageProbe::ownershipDefinition()));
    $unowned = new OwnershipUnrelatedModel;
    $remote = (new OwnershipUnrelatedModel)->setConnection('unclaimed_secondary');
    $registry->assertUnclaimed($unowned);
    $registry->assertUnclaimed($remote);

    OwnershipStorageProbe::$storageTable = $unowned->getTable();
    expect(fn () => $registry->assertUnclaimed($unowned))->toThrow(OwnershipViolation::class, 'Owned storage requires its exact configured model.');
    $registry->assertUnclaimed($remote);

    OwnershipStorageProbe::$storageConnection = 'unclaimed_secondary';
    expect(fn () => $registry->assertUnclaimed($remote))->toThrow(OwnershipViolation::class, 'Owned storage requires its exact configured model.');
    $registry->assertUnclaimed($unowned);

    OwnershipStorageProbe::$storageTable = 'ownership_storage_probes';
    OwnershipStorageProbe::$storageConnection = 'unclaimed_unconfigured';
    expect(fn () => $registry->assertUnclaimed($unowned))->toThrow(InvalidArgumentException::class);
    OwnershipStorageProbe::resetProbe();
});

it('enforces owned resources registered after an earlier unclaimed admission', function (): void {
    $registry = new OwnershipResourceRegistry;
    $registry->register(new OwnershipResourceDefinition('test.owners', 'test', PackageOwner::class, OwnershipDefinition::column(PackageOwner::class, 'company_id')));
    $registry->assertUnclaimed(new UnscopedOwnershipBase);
    $registry->register(new OwnershipResourceDefinition('test.claimed', 'test', OwnedOwnershipReplacement::class, OwnedOwnershipReplacement::ownershipDefinition()));
    expect(fn () => $registry->assertUnclaimed(new UnscopedOwnershipBase))->toThrow(OwnershipViolation::class)
        ->and(fn () => $registry->assertUnclaimed(new OwnershipUnrelatedModel))->not->toThrow(OwnershipViolation::class);
});

it('rejects native inherited declarations without a runtime', function (): void {
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('test.inherited', 'test', OwnershipCapableModel::class, OwnershipDefinition::inherited('parent'), parentResource: 'test.parents'));
    expect(fn () => app(OwnershipBoundary::class)->key('test.inherited', 'probe'))->toThrow(OwnershipViolation::class);
});
