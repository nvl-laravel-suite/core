<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Tests\SupportedSchemaDatabaseTestCase;

it('executes Core schema proofs on the requested disposable database engine', function (): void {
    expect(DB::connection()->getDriverName())->toBe(getenv('DB_CONNECTION'))
        ->and(DB::connection()->getDatabaseName())->toBe('nvl_core_test_ci');
})->skip(fn (): bool => getenv('NVL_SCHEMA_DATABASE') !== '1', 'Requires the explicitly selected disposable database.');

it('rejects host databases before supported schema fixture cleanup', function (): void {
    expect(fn () => SupportedSchemaDatabaseTestCase::validateDatabase('pgsql', 'host_business'))
        ->toThrow(InvalidArgumentException::class, 'disposable Core');
});

it('prevents a host connection URL from overriding the disposable schema database', function (): void {
    $originalDriver = getenv('DB_CONNECTION');
    $originalDatabase = getenv('DB_DATABASE');
    putenv('DB_CONNECTION=pgsql');
    putenv('DB_DATABASE=nvl_core_test_ci');

    try {
        $application = new Application;
        $configuration = new Repository([
            'database' => ['connections' => ['pgsql' => ['url' => 'pgsql://localhost/host_business']]],
        ]);
        $application->instance('config', $configuration);
        $fixture = new class('disposable configuration') extends SupportedSchemaDatabaseTestCase
        {
            public function configure(Application $application): void
            {
                $this->defineEnvironment($application);
            }
        };
        $fixture->configure($application);

        expect($configuration->get('database.connections.pgsql.url'))->toBeNull()
            ->and($configuration->get('database.connections.pgsql.database'))->toBe('nvl_core_test_ci');
    } finally {
        putenv($originalDriver === false ? 'DB_CONNECTION' : 'DB_CONNECTION='.$originalDriver);
        putenv($originalDatabase === false ? 'DB_DATABASE' : 'DB_DATABASE='.$originalDatabase);
    }
});
