<?php

declare(strict_types=1);

namespace Nvl\Support\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Exercises schema upgrades only against an explicitly selected disposable database. */
abstract class SupportedSchemaDatabaseTestCase extends TestCase
{
    /** Reject host databases before any schema cleanup or mutation. */
    public static function validateDatabase(string $driver, string $database): void
    {
        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true) || ($database !== 'nvl_core_test_ci' && preg_match('/^nvl_core_test_[a-f0-9]{8}_ci$/', $database) !== 1)) {
            throw new InvalidArgumentException('Supported schema tests require the disposable Core database [nvl_core_test_ci].');
        }
    }

    /**
     * Configure the actual selected engine without changing ordinary SQLite package tests.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $driver = (string) getenv('DB_CONNECTION');
        $database = (string) getenv('DB_DATABASE');
        self::validateDatabase($driver, $database);
        $app['config']->set('database.default', $driver);
        $app['config']->set('database.connections.'.$driver.'.url', null);
        $app['config']->set('database.connections.'.$driver.'.database', $database);
    }

    /** Reset only the validated dedicated schema between upgrade scenarios. */
    protected function setUp(): void
    {
        parent::setUp();
        $connection = DB::connection();
        self::validateDatabase($connection->getDriverName(), $connection->getDatabaseName());
        $connection->getSchemaBuilder()->dropAllTables();
    }
}
