<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Database\Connection;
use stdClass;
use WeakMap;

/**
 * Remembers protocol catalog probes per connection object within one request or job.
 *
 * Bound as a scoped service, so nothing outlives the framework lifecycle; a reconnected
 * connection is a new object and is probed again. Refusals are never remembered.
 *
 * @internal
 */
final class OwnershipStorageProbes
{
    /** @var WeakMap<Connection, array{protocol: bool, markers: list<stdClass>}> */
    private WeakMap $probes;

    /** @var WeakMap<Connection, array<string, true>> */
    private WeakMap $unpartitioned;

    public function __construct()
    {
        $this->forget();
    }

    /** @return array{protocol: bool, markers: list<stdClass>}|null */
    public function probe(Connection $connection): ?array
    {
        return $this->probes[$connection] ?? null;
    }

    /**
     * @param  array{protocol: bool, markers: list<stdClass>}  $probe
     * @return array{protocol: bool, markers: list<stdClass>}
     */
    public function rememberProbe(Connection $connection, array $probe): array
    {
        return $this->probes[$connection] = $probe;
    }

    /** Report whether the table was already found without a technical partition. */
    public function unpartitioned(Connection $connection, string $table): bool
    {
        return isset(($this->unpartitioned[$connection] ?? [])[$table]);
    }

    /** Record a table found without a technical partition. */
    public function rememberUnpartitioned(Connection $connection, string $table): void
    {
        $tables = $this->unpartitioned[$connection] ?? [];
        $tables[$table] = true;
        $this->unpartitioned[$connection] = $tables;
    }

    /** Drop every remembered probe. */
    public function forget(): void
    {
        $this->probes = new WeakMap;
        $this->unpartitioned = new WeakMap;
    }
}
