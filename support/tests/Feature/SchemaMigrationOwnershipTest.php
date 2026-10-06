<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Schema\SchemaIdentities;
use Nvl\Support\Schema\SchemaMigrationPaths;
use Nvl\Support\Schema\SchemaPreflight;

it('preflights an explicitly selected batch without running earlier host DDL', function (): void {
    Schema::create('nvl_settings_settings', fn (Blueprint $table) => $table->id());
    $path = InstalledVersions::getInstallPath('nvl/settings').'/database/migrations/2026_01_01_000000_nvl_settings_create_settings_table.php';
    $this->artisan('nvl:schema:preflight', ['--path' => [$path], '--realpath' => true, '--format' => 'json'])
        ->expectsOutputToContain('cannot create existing table')->assertFailed();
    expect(Schema::hasTable('host_earlier'))->toBeFalse();
});

it('guards owned migration events before their body and ignores previously included NVL files', function (): void {
    $path = InstalledVersions::getInstallPath('nvl/settings').'/database/migrations/2026_01_01_000000_nvl_settings_create_settings_table.php';
    $migration = require $path;
    Schema::create('nvl_settings_settings', fn (Blueprint $table) => $table->id());
    expect(fn () => Event::dispatch(new MigrationStarted($migration, 'up')))->toThrow(LogicException::class, 'cannot create existing table');
    $queries = [];
    DB::listen(function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });
    Event::dispatch(new MigrationStarted(new class extends Migration {}, 'up'));
    expect($queries)->toBe([]);
});

it('requires explicit exact declarations before recognizing identical published code', function (): void {
    $vendor = InstalledVersions::getInstallPath('nvl/settings').'/database/migrations/2026_01_01_000000_nvl_settings_create_settings_table.php';
    $path = sys_get_temp_dir().'/2099_01_01_'.bin2hex(random_bytes(4)).'_nvl_settings_create_settings_table.php';
    copy($vendor, $path);
    try {
        expect(SchemaIdentities::publishedMigration($path))->toBeNull();
        config(['nvl-core.migrations.published' => [$path => ['package' => 'settings', 'migration' => '2026_01_01_000000_nvl_settings_create_settings_table']]]);
        expect(app(SchemaMigrationPaths::class)->identity($path)['package'])->toBe('settings');
        expect(fn () => app(SchemaPreflight::class)->validate([$vendor, $path]))->toThrow(LogicException::class, 'two owners');
        file_put_contents($path, "<?php return new class {};\n");
        expect(fn () => app(SchemaMigrationPaths::class)->identity($path))->toThrow(LogicException::class, 'checksum');
    } finally {
        unlink($path);
    }
});

it('keeps host-only explicit selections free of package schema queries', function (): void {
    $queries = [];
    DB::listen(function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });
    $this->artisan('nvl:schema:preflight', ['--path' => [__FILE__], '--realpath' => true, '--format' => 'json'])
        ->expectsOutputToContain('"migrations": []')->assertSuccessful();
    expect($queries)->toBe([]);
});

it('retains native rollback selection and pretend behavior', function (string $command, array $options, bool $hostRemains, bool $settingsRemain): void {
    $directory = sys_get_temp_dir().'/nvl-native-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $host = $directory.'/2000_01_01_000000_host_earlier.php';
    file_put_contents($host, '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\Schema::create("host_earlier", fn ($table) => $table->id()); } public function down(): void { \\Illuminate\\Support\\Facades\\Schema::drop("host_earlier"); } };');
    $settings = InstalledVersions::getInstallPath('nvl/settings').'/database/migrations/2026_01_01_000000_nvl_settings_create_settings_table.php';
    try {
        app('migration.repository')->createRepository();
        app('migrator')->run([$host, $settings], ['step' => true]);
        $this->artisan($command, ['--path' => [$host, $settings], '--realpath' => true, ...$options])->assertSuccessful();
        expect(Schema::hasTable('host_earlier'))->toBe($hostRemains)
            ->and(Schema::hasTable('nvl_settings_settings'))->toBe($settingsRemain);
    } finally {
        unlink($host);
        rmdir($directory);
    }
})->with([
    'step' => ['migrate:rollback', ['--step' => 1], true, false],
    'batch' => ['migrate:rollback', ['--batch' => 2], true, false],
    'reset' => ['migrate:reset', [], false, false],
    'pretend' => ['migrate:reset', ['--pretend' => true], true, true],
]);

it('documents through execution that per-migration fallback permits earlier host DDL', function (): void {
    $directory = sys_get_temp_dir().'/nvl-fallback-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $host = $directory.'/2000_01_01_000000_host_earlier.php';
    file_put_contents($host, '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\Schema::create("host_earlier", fn ($table) => $table->id()); } };');
    $settings = InstalledVersions::getInstallPath('nvl/settings').'/database/migrations/2026_01_01_000000_nvl_settings_create_settings_table.php';
    Schema::create('nvl_settings_settings', fn (Blueprint $table) => $table->id());
    try {
        app('migration.repository')->createRepository();
        $this->artisan('nvl:schema:preflight', ['--path' => [$host, $settings], '--realpath' => true])->assertFailed();
        expect(Schema::hasTable('host_earlier'))->toBeFalse();
        expect(fn () => app('migrator')->run([$host, $settings]))->toThrow(LogicException::class, 'cannot create existing table');
        expect(Schema::hasTable('host_earlier'))->toBeTrue();
    } finally {
        unlink($host);
        rmdir($directory);
    }
});
