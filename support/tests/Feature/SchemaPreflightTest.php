<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Schema\PackageMigrator;
use Nvl\Support\Schema\SchemaIdentities;
use Nvl\Support\Schema\SchemaPreflight;
use Symfony\Component\Console\Output\BufferedOutput;

it('rejects the complete batch before an earlier table can be created', function (): void {
    Schema::create('nvl_forms_entries', function (Blueprint $table): void {
        $table->id();
        $table->string('foreign_host_payload');
    });
    $pending = array_map(fn (array $entry): string => '/host/database/migrations/'.$entry['name'].'.php', SchemaIdentities::package('forms')['migrations']);
    expect(fn () => app(SchemaPreflight::class)->validate($pending))->toThrow(LogicException::class, 'nvl:doctor')
        ->and(Schema::hasTable('nvl_forms_forms'))->toBeFalse();
});

it('allows a host table and migration sharing an old generic name', function (): void {
    Schema::create('forms', function (Blueprint $table): void {
        $table->id();
        $table->string('host_payload');
    });
    $first = array_values(SchemaIdentities::package('forms')['migrations'])[0];
    app(SchemaPreflight::class)->validate(['/host/database/migrations/'.$first['name'].'.php']);
    expect(Schema::hasTable('forms'))->toBeTrue()->and(Schema::hasTable('nvl_forms_forms'))->toBeFalse();
});

it('rejects duplicate creating identities with distinct published timestamps before ddl', function (): void {
    $first = array_values(SchemaIdentities::package('forms')['migrations'])[0]['name'];
    $published = '2099_01_01_000000_'.substr($first, 18);
    expect(fn () => app(SchemaPreflight::class)->validate([
        '/package/'.$first.'.php', '/host/'.$published.'.php',
    ]))->toThrow(LogicException::class, 'same target');
});

it('uses the guarded migrator for actual Laravel migration batches', function (): void {
    expect(app('migrator'))->toBeInstanceOf(PackageMigrator::class);
});

it('retains the configured Laravel migrator state when installing the preflight', function (): void {
    config(['database.connections.selected' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $original = new Migrator(app('migration.repository'), app('db'), app(Filesystem::class), app('events'));
    $output = new BufferedOutput;
    $original->path('/host/custom-migrations');
    $original->setOutput($output);
    $previous = DB::getDefaultConnection();
    try {
        $original->setConnection('selected');
        $guarded = PackageMigrator::guard($original, app(SchemaPreflight::class));
        expect($guarded->getConnection())->toBe('selected')
            ->and($guarded->paths())->toBe(['/host/custom-migrations'])
            ->and($guarded->getRepository())->toBe($original->getRepository())
            ->and($guarded->getFilesystem())->toBe($original->getFilesystem());
        $guarded->runPending([]);
        expect($output->fetch())->toContain('Nothing to migrate')
            ->and(PackageMigrator::guard($guarded, app(SchemaPreflight::class)))->toBe($guarded);
    } finally {
        DB::setDefaultConnection($previous);
    }
});

it('refuses to discard a host migrator subclass silently', function (): void {
    $custom = new class(app('migration.repository'), app('db'), app(Filesystem::class), app('events')) extends Migrator {};
    expect(fn () => PackageMigrator::guard($custom, app(SchemaPreflight::class)))
        ->toThrow(LogicException::class, 'extend Nvl\\Support\\Schema\\PackageMigrator');
});

it('preflights the ledger on its explicit connection before any default media ddl', function (): void {
    config(['database.connections.ledger' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'media.connections.owner_slot_operations' => 'ledger']);
    Schema::connection('ledger')->create('nvl_media_owner_slot_operations', fn (Blueprint $table) => $table->id());
    $pending = array_map(fn (array $entry): string => '/host/'.$entry['name'].'.php', SchemaIdentities::package('media')['migrations']);
    expect(fn () => app(SchemaPreflight::class)->validate($pending))->toThrow(LogicException::class, 'nvl_media_owner_slot_operations')
        ->and(Schema::hasTable('nvl_media_media'))->toBeFalse();
});

it('inherits the package connection for a null ledger override', function (): void {
    config(['database.connections.assets' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'media.connection' => 'assets', 'media.connections.owner_slot_operations' => null, 'media.owner_slots.idempotency.connection' => null]);
    Schema::connection('assets')->create('nvl_media_owner_slot_operations', fn (Blueprint $table) => $table->id());
    $pending = array_map(fn (array $entry): string => '/host/'.$entry['name'].'.php', SchemaIdentities::package('media')['migrations']);
    expect(PackageStorage::tableConnection('media', 'owner_slot_operations'))->toBe('assets')
        ->and(fn () => app(SchemaPreflight::class)->validate($pending))->toThrow(LogicException::class, 'nvl_media_owner_slot_operations');
});

it('rejects legacy storage with old history before any earlier unrelated ddl', function (): void {
    Schema::create('migrations', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('migration');
        $table->integer('batch');
    });
    Schema::create('forms', fn (Blueprint $table) => $table->uuid('id')->primary());
    $old = array_key_first(SchemaIdentities::package('forms')['migrations']);
    DB::table('migrations')->insert(['migration' => $old, 'batch' => 1]);
    $canonical = SchemaIdentities::package('forms')['migrations'][$old]['name'];
    expect(fn () => app(SchemaPreflight::class)->validate(['/host/2000_01_01_000000_unrelated.php', '/package/'.$canonical.'.php']))
        ->toThrow(LogicException::class, 'before creating parallel storage')
        ->and(Schema::hasTable('nvl_forms_forms'))->toBeFalse();
});
