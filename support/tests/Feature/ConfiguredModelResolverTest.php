<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Models\ConfiguredModelResolver;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;

/** Advertised extendable package model contract. */
class OwnershipConfigurableModel extends Model {}

/** Concrete final host replacement is a valid extension. */
final class OwnershipConfiguredModel extends OwnershipConfigurableModel {}

/** Unrelated host model cannot replace another package's model. */
final class OwnershipUnrelatedModel extends Model {}

/** Abstract replacement cannot be instantiated for canonical storage. */
abstract class OwnershipAbstractModel extends OwnershipConfigurableModel {}

it('resolves only concrete subclasses of the advertised model', function (): void {
    $resolver = new ConfiguredModelResolver;
    expect($resolver->resolve(OwnershipConfigurableModel::class, OwnershipConfigurableModel::class))->toBe(OwnershipConfigurableModel::class)
        ->and($resolver->resolve(OwnershipConfiguredModel::class, OwnershipConfigurableModel::class))->toBe(OwnershipConfiguredModel::class);
});

it('rejects unrelated missing and abstract configured classes', function (string $configured): void {
    expect(fn () => (new ConfiguredModelResolver)->resolve($configured, OwnershipConfigurableModel::class))
        ->toThrow(OwnershipConfigurationInvalid::class);
})->with([OwnershipUnrelatedModel::class, OwnershipAbstractModel::class, stdClass::class, 'MissingOwnershipModel']);
