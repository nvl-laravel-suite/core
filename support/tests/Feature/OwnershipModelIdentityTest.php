<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/** Model base for stable package morph identity replacement. */
class OwnershipIdentityBase extends Model {}

/** Explicit configured class retains its base's stored aliases. */
final class OwnershipIdentityReplacement extends OwnershipIdentityBase {}

/** Separate model family cannot take another replacement's registration. */
class OwnershipOtherIdentityBase extends Model {}

/** Concrete replacement belonging to the separate model family. */
final class OwnershipOtherIdentityReplacement extends OwnershipOtherIdentityBase {}

it('round trips a versioned scalar owner reference with stable partition identity', function (): void {
    $reference = new OwnerReference('company', 'application.companies', 'int', '7');
    expect(OwnerReference::fromArray($reference->toArray()))->toEqual($reference)
        ->and($reference->scopeKey())->toStartWith('owner:')
        ->and(strlen($reference->scopeKey()))->toBe(70)
        ->and((new OwnerReference('company', 'application.companies', 'int', '8'))->scopeKey())->not->toBe($reference->scopeKey())
        ->and((new OwnerReference('company', 'application.companies', 'string', '7'))->scopeKey())->not->toBe($reference->scopeKey())
        ->and((new OwnerReference('company', 'application.companies', 'string', '007'))->key)->toBe('007');
});

it('rejects ambiguous noncanonical and unknown identity payloads', function (array $payload): void {
    expect(fn () => OwnerReference::fromArray($payload))->toThrow(OwnershipConfigurationInvalid::class);
})->with([
    [[]],
    [['version' => 2, 'type' => 'company', 'storage' => 'companies', 'keyType' => 'int', 'key' => '7']],
    [['version' => 1, 'type' => 'company', 'storage' => 'companies', 'keyType' => 'int', 'key' => '007']],
    [['version' => 1, 'type' => 'company', 'storage' => 'companies', 'keyType' => 'int', 'key' => 7]],
    [['version' => 1, 'type' => 'company', 'storage' => 'companies', 'keyType' => 'string', 'key' => '7', 'extra' => true]],
]);

it('retains existing package and host aliases when installing an explicit replacement', function (): void {
    $original = Relation::morphMap();
    try {
        Relation::morphMap(['host-media' => OwnershipIdentityBase::class], false);
        $registry = new OwnerRegistry(new Repository);
        $registry->registerReplacement('nvl-media', OwnershipIdentityBase::class, OwnershipIdentityReplacement::class);
        expect(Relation::getMorphedModel('host-media'))->toBe(OwnershipIdentityReplacement::class)
            ->and((new OwnershipIdentityReplacement)->getMorphClass())->toBe('host-media')
            ->and($registry->model(OwnershipIdentityReplacement::class))->toBe(OwnershipIdentityReplacement::class)
            ->and($registry->model(OwnershipIdentityBase::class))->toBe(OwnershipIdentityReplacement::class)
            ->and($registry->reference(OwnershipIdentityBase::class, 'test.legacy-base'))->toBe(OwnershipIdentityReplacement::class)
            ->and(Relation::getMorphedModel(OwnershipIdentityBase::class))->toBe(OwnershipIdentityReplacement::class);
    } finally {
        Relation::morphMap($original, false);
    }
});

it('preserves stored class identities when the base had no morph alias', function (): void {
    $original = Relation::morphMap();
    try {
        Relation::morphMap([], false);
        $registry = new OwnerRegistry(new Repository);
        $registry->registerReplacement('nvl-media', OwnershipIdentityBase::class, OwnershipIdentityReplacement::class);
        expect((new OwnershipIdentityReplacement)->getMorphClass())->toBe(OwnershipIdentityBase::class)
            ->and(Relation::getMorphedModel(OwnershipIdentityBase::class))->toBe(OwnershipIdentityReplacement::class);
    } finally {
        Relation::morphMap($original, false);
    }
});

it('rejects an occupied package alias without altering the host map', function (): void {
    $original = Relation::morphMap();
    try {
        Relation::morphMap(['nvl-media' => Model::class], false);
        $map = Relation::morphMap();
        $registry = new OwnerRegistry(new Repository);
        expect(fn () => $registry->registerReplacement('nvl-media', OwnershipIdentityBase::class, OwnershipIdentityReplacement::class))
            ->toThrow(OwnershipConfigurationInvalid::class)
            ->and(Relation::morphMap())->toBe($map);
    } finally {
        Relation::morphMap($original, false);
    }
});

it('rejects replacement registration reuse before modifying any host aliases', function (): void {
    $original = Relation::morphMap();
    try {
        Relation::morphMap(['host-media' => OwnershipIdentityBase::class, 'host-other' => OwnershipOtherIdentityBase::class], false);
        $registry = new OwnerRegistry(new Repository);
        $registry->registerReplacement('nvl-media', OwnershipIdentityBase::class, OwnershipIdentityReplacement::class);
        $map = Relation::morphMap();
        expect(fn () => $registry->registerReplacement('nvl-media', OwnershipOtherIdentityBase::class, OwnershipOtherIdentityReplacement::class))
            ->toThrow(OwnershipConfigurationInvalid::class)
            ->and(Relation::morphMap())->toBe($map);
    } finally {
        Relation::morphMap($original, false);
    }
});
