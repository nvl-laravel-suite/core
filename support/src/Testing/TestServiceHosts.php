<?php

declare(strict_types=1);

namespace Nvl\Support\Testing;

use RuntimeException;

/**
 * Names the only database and Redis hosts that package test fixtures may connect to.
 *
 * Loopback addresses and the local socket directory are always admitted. The service names that the
 * local Dagger workflow binds (`database` and `redis`) are admitted only when the package test runner
 * marks the process with NVL_TEST_SERVICE_PROFILE=dagger. Port and disposable-database-name checks stay
 * with each fixture.
 *
 * @api
 */
final class TestServiceHosts
{
    public const string PROFILE_VARIABLE = 'NVL_TEST_SERVICE_PROFILE';

    public const string REDIS_HOST_VARIABLE = 'NVL_TEST_REDIS_HOST';

    private const array LOCAL_DATABASE_HOSTS = ['127.0.0.1', 'localhost', '::1', '/tmp'];

    private const array LOCAL_REDIS_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    /** Whether a fixture may open a disposable database on this host. */
    public static function admitsDatabaseHost(mixed $host): bool
    {
        return in_array($host, self::LOCAL_DATABASE_HOSTS, true)
            || (self::isDaggerProfile() && $host === 'database');
    }

    /** Whether a fixture may use a disposable Redis service on this host. */
    public static function admitsRedisHost(mixed $host): bool
    {
        return in_array($host, self::LOCAL_REDIS_HOSTS, true)
            || (self::isDaggerProfile() && $host === 'redis');
    }

    /** Resolve the forwarded Redis fixture host, refusing any host outside the allow-list. */
    public static function redisHost(): string
    {
        $host = getenv(self::REDIS_HOST_VARIABLE);
        $host = is_string($host) && $host !== '' ? $host : '127.0.0.1';

        if (! self::admitsRedisHost($host)) {
            throw new RuntimeException('Redis test fixtures require a loopback or bound disposable Redis service.');
        }

        return $host;
    }

    private static function isDaggerProfile(): bool
    {
        return getenv(self::PROFILE_VARIABLE) === 'dagger';
    }
}
