<?php

declare(strict_types=1);

use Nvl\Support\Config\PackageStorage;

it('resolves package table overrides without changing other tables', function (): void {
    config(['nvl-forms.tables.forms' => 'host_form_builder']);

    expect(PackageStorage::table('forms', 'forms', 'nvl_forms_forms'))->toBe('host_form_builder')
        ->and(PackageStorage::table('forms', 'entries', 'nvl_forms_entries'))->toBe('nvl_forms_entries');
});

it('inherits Core connections and preserves package overrides', function (): void {
    config(['nvl-core.connection' => 'suite', 'nvl-forms.connection' => null]);
    expect(PackageStorage::connection('forms'))->toBe('suite');
    config(['nvl-forms.connection' => 'forms_storage']);
    expect(PackageStorage::connection('forms'))->toBe('forms_storage');
});

it('normalizes explicit legacy storage before canonical defaults are merged', function (): void {
    $host = ['storage' => ['connection' => 'historical', 'table' => 'host_audit']];
    $normalized = PackageStorage::normalize('activity', $host);
    expect($normalized['connection'])->toBe('historical')
        ->and($normalized['tables']['log'])->toBe('host_audit');
});

it('preserves an explicit canonical value when an old key is also configured', function (): void {
    $normalized = PackageStorage::normalize('activity', [
        'connection' => 'canonical',
        'tables' => ['log' => 'canonical_audit'],
        'storage' => ['connection' => 'old', 'table' => 'old_audit'],
    ]);
    expect($normalized['connection'])->toBe('canonical')
        ->and($normalized['tables']['log'])->toBe('canonical_audit');
});

it('rejects malformed storage identifiers before executing SQL', function (mixed $value): void {
    config(['nvl-forms.tables.forms' => $value]);
    expect(fn (): string => PackageStorage::table('forms', 'forms', 'nvl_forms_forms'))
        ->toThrow(InvalidArgumentException::class);
})->with(['empty' => '', 'SQL fragment' => 'forms; DROP TABLE users', 'wrong type' => 42]);

it('normalizes explicit Laravel enum connection aliases without replacing them', function (): void {
    expect(PackageStorage::connectionName(SchemaConnectionAlias::Host))->toBe('host')
        ->and(PackageStorage::connectionName(SchemaNumericConnectionAlias::Host))->toBe('3')
        ->and(PackageStorage::connectionName(null))->toBeNull();
});

/** Represents a string-backed Laravel connection alias. */
enum SchemaConnectionAlias: string
{
    case Host = 'host';
}

/** Represents a numeric-backed alias accepted by Laravel's enum resolution. */
enum SchemaNumericConnectionAlias: int
{
    case Host = 3;
}
