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

it('ignores historical storage options when inheriting canonical defaults', function (): void {
    config(['nvl-core.connection' => 'native', 'nvl-activity' => ['storage' => ['connection' => 'historical', 'table' => 'host_audit']]]);
    expect(PackageStorage::connection('activity'))->toBe('native')
        ->and(PackageStorage::table('activity', 'log', 'nvl_activity_log'))->toBe('nvl_activity_log');
});

it('reads canonical overrides without translating historical keys', function (): void {
    config(['nvl-activity' => [
        'connection' => 'canonical',
        'tables' => ['log' => 'canonical_audit'],
        'storage' => ['connection' => 'old', 'table' => 'old_audit'],
    ]]);
    expect(PackageStorage::connection('activity'))->toBe('canonical')
        ->and(PackageStorage::table('activity', 'log', 'nvl_activity_log'))->toBe('canonical_audit');
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

it('keeps optional ownership protocol tables fixed across configuration replacement', function (): void {
    config()->set('nvl-ownership.tables.stores', 'renamed_ownership_stores');
    expect(fn () => PackageStorage::resolveTable('ownership', 'stores'))->toThrow(InvalidArgumentException::class, 'fixed');
});
