<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Schema\SchemaIdentities;

it('protects host storage and history for every schema-owning package', function (string $package): void {
    $manifest = SchemaIdentities::package($package);
    config([$manifest['config'] => []]);
    $migrationName = array_find_key($manifest['migrations'], fn (array $migration): bool => $migration['creates'] !== []);
    $migration = $manifest['migrations'][$migrationName];
    $table = $manifest['tables'][$migration['creates'][0]];
    Schema::create('migrations', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('migration');
        $table->integer('batch');
    });
    Schema::create($table['legacy'], function (Blueprint $table): void {
        $table->id();
        $table->string('host_payload');
    });
    DB::table($table['legacy'])->insert(['host_payload' => 'untouched']);
    DB::table('migrations')->insert(['migration' => $migrationName, 'batch' => 7]);
    $path = InstalledVersions::getInstallPath('nvl/'.$package).'/'.$migration['path'];
    try {
        app('migrator')->run([$path]);
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toContain('nvl:doctor');
    }
    expect(DB::table($table['legacy'])->sole()->host_payload)->toBe('untouched')
        ->and(Schema::getColumnListing($table['legacy']))->toBe(['id', 'host_payload'])
        ->and(DB::table('migrations')->where('migration', $migrationName)->value('batch'))->toBe(7);
})->with(array_keys(SchemaIdentities::all()));
