<?php

declare(strict_types=1);

use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Tests\Fixtures\OwnedOwnershipReplacement;
use Nvl\Support\Tests\Fixtures\OwnershipCapableModel;
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
