<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Tests\Fixtures\OwnershipCapableModel;

/** Native integer-key owner used to validate declarative ownership metadata. */
final class OwnershipDefinitionCompany extends Model {}

it('declares column and exclusive pivot ownership without opening a connection', function (): void {
    $column = OwnershipDefinition::column(OwnershipDefinitionCompany::class, 'company_id');
    $pivot = OwnershipDefinition::pivot(OwnershipDefinitionCompany::class, 'product_owners', 'product_id', 'company_id');

    expect($column->strategy)->toBe('column')
        ->and($column->owner)->toBe(OwnershipDefinitionCompany::class)
        ->and($column->foreignKey)->toBe('company_id')
        ->and($pivot->strategy)->toBe('pivot')
        ->and($pivot->table)->toBe('product_owners')
        ->and($pivot->resourceKey)->toBe('product_id')
        ->and($pivot->ownerKey)->toBe('company_id');
});

it('keeps default host declarations unscoped and inheritance explicit', function (): void {
    expect(OwnershipCapableModel::ownershipDefinition()->strategy)->toBe('unscoped')
        ->and(OwnershipDefinition::inherited('product')->relation)->toBe('product')
        ->and(OwnershipDefinition::captured()->strategy)->toBe('captured');
});

it('rejects malformed or executable ownership identifiers', function (string $identifier): void {
    expect(fn () => OwnershipDefinition::column(OwnershipDefinitionCompany::class, $identifier))
        ->toThrow(OwnershipConfigurationInvalid::class);
    expect(fn () => OwnershipDefinition::pivot(OwnershipDefinitionCompany::class, $identifier, 'product_id', 'company_id'))
        ->toThrow(OwnershipConfigurationInvalid::class);
})->with(['', 'company id', 'company_id;drop', 'table--comment', 'company.id']);

it('rejects invalid owner classes and relation declarations', function (): void {
    expect(fn () => OwnershipDefinition::column(stdClass::class, 'company_id'))->toThrow(OwnershipConfigurationInvalid::class)
        ->and(fn () => OwnershipDefinition::column(Model::class, 'company_id'))->toThrow(OwnershipConfigurationInvalid::class)
        ->and(fn () => OwnershipDefinition::inherited('product.owner'))->toThrow(OwnershipConfigurationInvalid::class);
});
