<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Support\Models\ConfiguredModelRegistration;
use Nvl\Support\Models\ConfiguredModelResolver;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Tests\Fixtures\OwnershipAbstractModel;
use Nvl\Support\Tests\Fixtures\OwnershipConfigurableModel;
use Nvl\Support\Tests\Fixtures\OwnershipConfiguredModel;
use Nvl\Support\Tests\Fixtures\OwnershipUnrelatedModel;

it('resolves only concrete subclasses of the advertised model', function (): void {
    $resolver = new ConfiguredModelResolver;
    expect($resolver->resolve(OwnershipConfigurableModel::class, OwnershipConfigurableModel::class))->toBe(OwnershipConfigurableModel::class)
        ->and($resolver->resolve(OwnershipConfiguredModel::class, OwnershipConfigurableModel::class))->toBe(OwnershipConfiguredModel::class);
});

it('rejects unrelated missing and abstract configured classes', function (string $configured): void {
    expect(fn () => (new ConfiguredModelResolver)->resolve($configured, OwnershipConfigurableModel::class))
        ->toThrow(OwnershipConfigurationInvalid::class);
})->with([OwnershipUnrelatedModel::class, OwnershipAbstractModel::class, stdClass::class, 'MissingOwnershipModel']);

it('freezes a configured model only after its package declaration has been accepted', function (): void {
    $original = Relation::morphMap();
    try {
        $configuration = new Repository(['models' => ['record' => OwnershipConfiguredModel::class]]);
        $identities = new OwnerRegistry($configuration);
        $accepted = false;
        $registration = new ConfiguredModelRegistration($configuration, $identities, 'models.record', OwnershipConfigurableModel::class, 'fixture.records', static function (string $class) use (&$accepted): void {
            if (! $accepted) {
                throw new OwnershipConfigurationInvalid('The package declaration is not accepted.');
            }
        });
        expect(fn () => $registration->prepare())->toThrow(OwnershipConfigurationInvalid::class)
            ->and(Relation::morphMap())->toBe($original);
        $accepted = true;
        expect($registration->prepare())->toBe(OwnershipConfiguredModel::class);
        $configuration->set('models.record', OwnershipUnrelatedModel::class);
        expect($registration->prepare())->toBe(OwnershipConfiguredModel::class)
            ->and($identities->model(OwnershipConfigurableModel::class))->toBe(OwnershipConfiguredModel::class);
    } finally {
        Relation::morphMap($original, false);
    }
});
