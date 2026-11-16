<?php

declare(strict_types=1);

namespace Nvl\Support\Integrations;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use InvalidArgumentException;

/** @internal Resolves and inspects a PostgreSQL collation suitable for ILIKE. */
final readonly class PostgresSearchCollation
{
    /** Retain the host's optional collation override. */
    public function __construct(private Repository $config) {}

    /**
     * Quote the configured identifier or select the Unicode default for the server.
     *
     * @return literal-string
     */
    public function resolve(Connection $connection): string
    {
        $name = $this->config->get('nvl-core.search.postgres_collation');
        if ($name === null) {
            return version_compare($connection->getServerVersion(), '17', '>=')
                ? '"pg_catalog"."pg_c_utf8"'
                : '"pg_catalog"."und-x-icu"';
        }

        if (! is_string($name) || preg_match('/\A[A-Za-z_][A-Za-z0-9_-]{0,62}(?:\.[A-Za-z_][A-Za-z0-9_-]{0,62})?\z/', $name) !== 1) {
            throw new InvalidArgumentException('nvl-core.search.postgres_collation must name a PostgreSQL collation, optionally qualified by schema.');
        }

        $quoted = '"'.str_replace('.', '"."', $name).'"';
        $this->assertQuotedIdentifier($quoted);

        return $quoted;
    }

    /** Check existence, database encoding compatibility and determinism. */
    public function verify(Connection $connection): string
    {
        $collation = $this->resolve($connection);
        $exists = $connection->table('pg_catalog.pg_collation')
            ->whereRaw('oid = pg_catalog.to_regcollation(?)', [$collation])
            ->where('collisdeterministic', true)
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException("PostgreSQL text search requires a deterministic collation. [{$collation}] is unavailable or nondeterministic. Set nvl-core.search.postgres_collation to a Unicode-capable collation on this database.");
        }

        return $collation;
    }

    /** @phpstan-assert literal-string $identifier */
    private function assertQuotedIdentifier(string $identifier): void
    {
        if (preg_match('/\A"[A-Za-z0-9_-]+"(?:\."[A-Za-z0-9_-]+")?\z/', $identifier) !== 1) {
            throw new InvalidArgumentException('Invalid quoted PostgreSQL collation identifier.');
        }
    }
}
