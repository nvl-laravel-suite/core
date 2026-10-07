<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Schema\SchemaIdentities;
use Nvl\Support\Schema\SchemaPreflight;
use Nvl\Support\Schema\SchemaUpgrade;

beforeEach(function (): void {
    config(['nvl-settings' => []]);
    Schema::create('migrations', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('migration');
        $table->integer('batch');
    });
});

it('validates a claimed installation then renames tables and exact records idempotently', function (): void {
    nvlLegacySettingsSchema();
    DB::table('settings')->insert(['id' => '5d9dc5b3-8d2f-4ae2-bdbc-f07ca314a57a', 'namespace' => 'host', 'scope' => '', 'key' => 'timezone', 'type' => 'string', 'value' => '"Europe/Sofia"', 'definition_hash' => str_repeat('a', 64)]);
    $old = array_key_first(SchemaIdentities::package('settings')['migrations']);
    $new = SchemaIdentities::package('settings')['migrations'][$old]['name'];
    DB::table('migrations')->insert([
        ['migration' => $old, 'batch' => 8],
        ['migration' => '2024_01_01_000000_host_settings', 'batch' => 9],
    ]);
    $upgrade = app(SchemaUpgrade::class);
    $plan = $upgrade->plan(['settings'], true);
    expect($plan['steps'])->toHaveCount(2)->and(Schema::hasTable('settings'))->toBeTrue();
    $upgrade->execute($plan);
    expect(Schema::hasTable('settings'))->toBeFalse()
        ->and(Schema::hasTable('nvl_settings_settings'))->toBeTrue()
        ->and(DB::table('migrations')->where('migration', $new)->value('batch'))->toBe(8)
        ->and(DB::table('migrations')->where('migration', '2024_01_01_000000_host_settings')->value('batch'))->toBe(9)
        ->and(DB::table('nvl_settings_settings')->where('key', 'timezone')->value('value'))->toBe('"Europe/Sofia"')
        ->and($upgrade->plan(['settings'], true)['steps'])->toBe([]);
});

it('refuses unrelated legacy shapes before making any changes', function (): void {
    Schema::create('settings', function (Blueprint $table): void {
        $table->id();
        $table->string('host_payload');
    });
    $old = array_key_first(SchemaIdentities::package('settings')['migrations']);
    DB::table('migrations')->insert(['migration' => $old, 'batch' => 1]);
    expect(fn () => app(SchemaUpgrade::class)->plan(['settings'], true))->toThrow(LogicException::class)
        ->and(Schema::hasTable('settings'))->toBeTrue()
        ->and(DB::table('migrations')->value('migration'))->toBe($old);
});

it('requires explicit ownership before planning a legacy rename', function (): void {
    expect(fn () => app(SchemaUpgrade::class)->plan(['settings'], false))->toThrow(LogicException::class, '--claim-legacy');
});

it('preserves a matching generic host migration record when no owned storage exists', function (): void {
    $old = array_key_first(SchemaIdentities::package('settings')['migrations']);
    DB::table('migrations')->insert(['migration' => $old, 'batch' => 4]);
    expect(fn () => app(SchemaUpgrade::class)->plan(['settings'], true))->toThrow(LogicException::class, 'name alone')
        ->and(DB::table('migrations')->value('migration'))->toBe($old);
});

it('runs a machine readable dry run without mutating the migration repository', function (): void {
    $this->artisan('nvl:schema:upgrade', ['--package' => ['settings'], '--claim-legacy' => true, '--dry-run' => true, '--format' => 'json'])
        ->expectsOutputToContain('"dry_run": true')->assertSuccessful();
    expect(DB::table('migrations')->count())->toBe(0);
});

/** Create the actual released shape with its primary, unique, and lookup keys. */
function nvlLegacySettingsSchema(): void
{
    config(['nvl-settings.tables.settings' => 'settings']);
    $migration = require dirname(__DIR__, 4).'/settings/database/migrations/2026_01_01_000000_nvl_settings_create_settings_table.php';
    $migration->up();
    config(['nvl-settings' => []]);
}

it('rejects familiar columns when required relational keys are missing', function (): void {
    nvlLegacySettingsSchema();
    Schema::table('settings', fn (Blueprint $table) => $table->dropUnique(['namespace', 'scope', 'key']));
    $old = array_key_first(SchemaIdentities::package('settings')['migrations']);
    DB::table('migrations')->insert(['migration' => $old, 'batch' => 3]);
    expect(fn () => app(SchemaUpgrade::class)->plan(['settings'], true))->toThrow(LogicException::class, 'missing its released unique')
        ->and(Schema::hasTable('settings'))->toBeTrue()
        ->and(DB::table('migrations')->value('migration'))->toBe($old);
});

it('reconciles explicitly mapped published code and history to the actual native filename', function (): void {
    nvlLegacySettingsSchema();
    $directory = sys_get_temp_dir().'/nvl-published-'.bin2hex(random_bytes(8));
    mkdir($directory.'/migrations', 0777, true);
    $this->app->useDatabasePath($directory);
    $old = array_key_first(SchemaIdentities::package('settings')['migrations']);
    $canonical = SchemaIdentities::package('settings')['migrations'][$old]['name'];
    $recorded = '2098_01_01_000000_'.substr($old, 18);
    $native = '2099_01_01_000000_'.substr($canonical, 18);
    $path = $directory.'/migrations/'.$native.'.php';
    copy(dirname(__DIR__, 4).'/settings/database/migrations/'.$canonical.'.php', $path);
    config(['nvl-settings.migrations.enabled' => false, 'nvl-core.migrations.published' => [$path => ['package' => 'settings', 'migration' => $canonical, 'legacy' => $recorded]]]);
    DB::table('migrations')->insert(['migration' => $recorded, 'batch' => 8]);
    try {
        $upgrade = app(SchemaUpgrade::class);
        $plan = $upgrade->plan(['settings'], true, 'published');
        expect($plan['migration_owner'])->toBe('published')->and($plan['files'][0]['path'])->toBe(realpath($path));
        $upgrade->execute($plan);
        $migrator = app('migrator');
        expect(DB::table('migrations')->value('migration'))->toBe($native)
            ->and(DB::table('migrations')->value('batch'))->toBe(8)
            ->and(array_keys($migrator->getMigrationFiles([$path])))->toBe([$native])
            ->and($migrator->run([$path]))->toBe([]);
        $this->artisan('migrate:status', ['--path' => [$path], '--realpath' => true])->expectsOutputToContain($native)->assertSuccessful();
        $migrator->rollback([$path]);
        expect(Schema::hasTable('nvl_settings_settings'))->toBeFalse()->and(DB::table('migrations')->count())->toBe(0);
    } finally {
        unlink($path);
        rmdir($directory.'/migrations');
        rmdir($directory);
    }
});

it('rejects duplicate owners explicitly and leaves modified unclaimed host code native', function (): void {
    $directory = sys_get_temp_dir().'/nvl-published-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $old = array_key_first(SchemaIdentities::package('settings')['migrations']);
    $canonical = SchemaIdentities::package('settings')['migrations'][$old]['name'];
    $path = $directory.'/2099_01_01_000000_'.substr($canonical, 18).'.php';
    $vendor = dirname(__DIR__, 4).'/settings/database/migrations/'.$canonical.'.php';
    copy($vendor, $path);
    config(['nvl-core.migrations.published' => [$path => ['package' => 'settings', 'migration' => $canonical]]]);
    try {
        expect(fn () => app(SchemaPreflight::class)->validate([$path, $vendor]))->toThrow(LogicException::class, 'two owners');
        file_put_contents($path, "<?php // Host owned migration.\n");
        config(['nvl-core.migrations.published' => []]);
        expect(app('migrator')->getMigrationName($path))->toBe(pathinfo($path, PATHINFO_FILENAME))
            ->and(app('migrator')->getMigrationFiles([$path]))->toBe([pathinfo($path, PATHINFO_FILENAME) => $path]);
    } finally {
        unlink($path);
        rmdir($directory);
    }
});

it('requires vendor archival of exact claimed old code before changing storage', function (): void {
    nvlLegacySettingsSchema();
    $directory = sys_get_temp_dir().'/nvl-archive-'.bin2hex(random_bytes(8));
    mkdir($directory.'/migrations', 0777, true);
    $this->app->useDatabasePath($directory);
    $old = array_key_first(SchemaIdentities::package('settings')['migrations']);
    $canonical = SchemaIdentities::package('settings')['migrations'][$old]['name'];
    $path = $directory.'/migrations/'.$old.'.php';
    copy(__DIR__.'/../Fixtures/released-settings-migration.txt', $path);
    config(['nvl-core.migrations.published' => [$path => ['package' => 'settings', 'migration' => $canonical]]]);
    DB::table('migrations')->insert(['migration' => $old, 'batch' => 8]);
    try {
        $upgrade = app(SchemaUpgrade::class);
        $plan = $upgrade->plan(['settings'], true, 'vendor');
        expect($plan['files'][0]['action'])->toBe('archive');
        expect(fn () => $upgrade->execute($plan))->toThrow(LogicException::class, 'archive')
            ->and(Schema::hasTable('settings'))->toBeTrue()->and(is_file($path))->toBeTrue();
        $archive = $directory.'/'.$old.'.php';
        rename($path, $archive);
        config(['nvl-core.migrations.published' => [$archive => ['package' => 'settings', 'migration' => $canonical]]]);
        $upgrade->execute($upgrade->plan(['settings'], true, 'vendor'));
        $vendor = dirname(__DIR__, 4).'/settings/database/migrations/'.$canonical.'.php';
        expect(app('migrator')->run([$vendor]))->toBe([]);
        app('migrator')->rollback([$vendor]);
        expect(Schema::hasTable('nvl_settings_settings'))->toBeFalse()->and(DB::table('migrations')->count())->toBe(0);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        if (isset($archive) && is_file($archive)) {
            unlink($archive);
        }
        rmdir($directory.'/migrations');
        rmdir($directory);
    }
});

it('refuses retimestamped legacy records without an exact host mapping', function (bool $currentName): void {
    nvlLegacySettingsSchema();
    $old = array_key_first(SchemaIdentities::package('settings')['migrations']);
    $name = $currentName ? SchemaIdentities::package('settings')['migrations'][$old]['name'] : $old;
    DB::table('migrations')->insert(['migration' => '2099_01_01_000000_'.substr($name, 18), 'batch' => 8]);
    expect(fn () => app(SchemaUpgrade::class)->plan(['settings'], true))->toThrow(LogicException::class, 'explicit mapping');
})->with(['released name' => false, 'current name' => true]);

it('keeps published ownership fail closed until code and vendor loading are reconciled', function (string $state): void {
    nvlLegacySettingsSchema();
    $old = array_key_first(SchemaIdentities::package('settings')['migrations']);
    $canonical = SchemaIdentities::package('settings')['migrations'][$old]['name'];
    $path = sys_get_temp_dir().'/nvl-unreconciled-'.bin2hex(random_bytes(8)).'.php';
    $source = $state === 'old code' ? __DIR__.'/../Fixtures/released-settings-migration.txt' : dirname(__DIR__, 4).'/settings/database/migrations/'.$canonical.'.php';
    copy($source, $path);
    if ($state === 'modified code') {
        file_put_contents($path, "<?php // Host code requires reconciliation.\n");
    }
    $contents = file_get_contents($path);
    config(['nvl-settings.migrations.enabled' => $state === 'vendor enabled', 'nvl-core.migrations.published' => [$path => ['package' => 'settings', 'migration' => $canonical]]]);
    DB::table('migrations')->insert(['migration' => $old, 'batch' => 8]);
    try {
        expect(fn () => app(SchemaUpgrade::class)->plan(['settings'], true, 'published'))->toThrow(LogicException::class)
            ->and(file_get_contents($path))->toBe($contents)
            ->and(Schema::hasTable('settings'))->toBeTrue()
            ->and(Schema::hasTable('nvl_settings_settings'))->toBeFalse()
            ->and(DB::table('migrations')->value('migration'))->toBe($old);
    } finally {
        unlink($path);
    }
})->with(['vendor enabled', 'old code', 'modified code']);

it('rejects a recorded multi-table creator when one owned table is missing', function (): void {
    config(['nvl-billing' => []]);
    Schema::create('nvl_billing_accounts', fn (Blueprint $table) => $table->uuid('id')->primary());
    $old = array_key_first(SchemaIdentities::package('billing')['migrations']);
    DB::table('migrations')->insert(['migration' => $old, 'batch' => 4]);
    expect(fn () => app(SchemaUpgrade::class)->plan(['billing'], true))->toThrow(LogicException::class, 'incomplete NVL storage')
        ->and(DB::table('migrations')->value('migration'))->toBe($old);
});

it('rejects schema-qualified legacy rename targets before changing storage', function (): void {
    nvlLegacySettingsSchema();
    config(['nvl-settings.tables.settings' => 'archive.nvl_settings_settings']);
    $old = array_key_first(SchemaIdentities::package('settings')['migrations']);
    DB::table('migrations')->insert(['migration' => $old, 'batch' => 4]);
    expect(fn () => app(SchemaUpgrade::class)->plan(['settings'], true))->toThrow(LogicException::class, 'unqualified target')
        ->and(Schema::hasTable('settings'))->toBeTrue();
});

it('rolls back a supported table rename when the migration history update fails', function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
        $this->markTestSkipped('Requires transactional table renames.');
    }
    nvlLegacySettingsSchema();
    $old = array_key_first(SchemaIdentities::package('settings')['migrations']);
    DB::table('migrations')->insert(['migration' => $old, 'batch' => 7]);
    $interrupted = false;
    DB::listen(function (QueryExecuted $query) use (&$interrupted, $old): void {
        if (! $interrupted && str_contains(strtolower($query->sql), 'rename to') && str_contains($query->sql, 'nvl_settings_settings')) {
            $interrupted = true;
            DB::table('migrations')->where('migration', $old)->delete();
        }
    });
    $upgrade = app(SchemaUpgrade::class);
    expect(fn () => $upgrade->execute($upgrade->plan(['settings'], true)))
        ->toThrow(LogicException::class, 'changed during upgrade')
        ->and(Schema::hasTable('settings'))->toBeTrue()
        ->and(Schema::hasTable('nvl_settings_settings'))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', $old)->value('batch'))->toBe(7);
});

it('resumes from a verified completed rename without repeating it', function (): void {
    nvlLegacySettingsSchema();
    $old = array_key_first(SchemaIdentities::package('settings')['migrations']);
    DB::table('migrations')->insert(['migration' => $old, 'batch' => 7]);
    Schema::rename('settings', 'nvl_settings_settings');
    $upgrade = app(SchemaUpgrade::class);
    $plan = $upgrade->plan(['settings'], true);
    expect($plan['steps'])->toHaveCount(1)->and($plan['steps'][0]['kind'])->toBe('migration');
    $upgrade->execute($plan);
    expect(DB::table('migrations')->value('migration'))->toBe(SchemaIdentities::package('settings')['migrations'][$old]['name'])
        ->and(DB::table('migrations')->value('batch'))->toBe(7)
        ->and($upgrade->plan(['settings'], true)['steps'])->toBe([]);
});

it('rejects duplicate configured targets before executing an otherwise valid rename', function (): void {
    nvlLegacySettingsSchema();
    config(['nvl-forms' => ['tables' => ['forms' => 'nvl_settings_settings']]]);
    $old = array_key_first(SchemaIdentities::package('settings')['migrations']);
    DB::table('migrations')->insert(['migration' => $old, 'batch' => 7]);
    expect(fn () => app(SchemaUpgrade::class)->plan(['settings', 'forms'], true))
        ->toThrow(LogicException::class, 'share configured target')
        ->and(Schema::hasTable('settings'))->toBeTrue()
        ->and(Schema::hasTable('nvl_settings_settings'))->toBeFalse()
        ->and(DB::table('migrations')->value('migration'))->toBe($old);
});

it('executes the native schema upgrade command and preserves text and JSON failure output', function (): void {
    $this->artisan('nvl:schema:upgrade', ['--package' => ['settings'], '--claim-legacy' => true])
        ->expectsOutputToContain('Schema upgrade completed.')->assertSuccessful();
    $this->artisan('nvl:schema:upgrade', ['--package' => ['settings'], '--claim-legacy' => true, '--dry-run' => true])
        ->expectsOutputToContain('Dry run validated; no storage changed.')->assertSuccessful();
    $this->artisan('nvl:schema:upgrade', ['--format' => 'yaml'])
        ->expectsOutputToContain('The format must be text or json.')->assertFailed();
    $this->artisan('nvl:schema:upgrade', ['--package' => [''], '--format' => 'json'])
        ->expectsOutputToContain('Each --package must name a package slug.')->assertFailed();
    expect(DB::table('migrations')->count())->toBe(0);
});
