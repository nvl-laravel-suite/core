<?php

declare(strict_types=1);

use Nvl\Support\Models\ConfiguredModelResolver;
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
