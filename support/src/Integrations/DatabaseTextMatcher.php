<?php

declare(strict_types=1);

namespace Nvl\Support\Integrations;

use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\SQLiteConnection;
use InvalidArgumentException;
use Nvl\Support\Contracts\TextMatcher;
use PDO;
use Pdo\Sqlite;
use RuntimeException;
use WeakMap;

/** @internal Provides native case-insensitive PostgreSQL and SQLite text predicates. */
final class DatabaseTextMatcher implements TextMatcher
{
    /** @var WeakMap<PDO, true> */
    private WeakMap $sqliteFunctions;

    /** Retain the selected PostgreSQL collation and track live SQLite connections. */
    public function __construct(private readonly PostgresSearchCollation $collation)
    {
        $this->sqliteFunctions = new WeakMap;
    }

    /** {@inheritDoc} */
    public function contains(Builder $query, string $column, string $value, bool $negated = false, bool $wildcards = false, string $boolean = 'and'): Builder
    {
        if (! in_array($boolean, ['and', 'or'], true) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\z/', $column) !== 1) {
            throw new InvalidArgumentException('Text predicates require a column identifier and an and/or conjunction.');
        }

        $wrapped = $query->getGrammar()->wrap($column);
        $this->assertWrappedColumn($wrapped);
        $connection = $query->getConnection();
        $pattern = '%'.strtr($value, $wildcards ? ['!' => '!!'] : ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        $not = $negated ? 'NOT ' : '';

        if ($connection instanceof PostgresConnection) {
            $collation = $this->collation->resolve($connection);
            $text = "CAST({$wrapped} AS TEXT)";
            if (str_contains($pattern, 'σ') || str_contains($pattern, 'Σ') || str_contains($pattern, 'ς')) {
                // ICU and libc lowercase sigma differently at word boundaries.
                $text = "pg_catalog.translate({$text}, 'Σς', 'σσ')";
                $pattern = strtr($pattern, ['Σ' => 'σ', 'ς' => 'σ']);
            }

            return $query->whereRaw("({$text} COLLATE {$collation}) {$not}ILIKE ? ESCAPE '!'", [$pattern], $boolean);
        }

        if ($connection instanceof SQLiteConnection) {
            $this->registerSqliteFunction($connection);
            $wrapped = "nvl_search_casefold(CAST({$wrapped} AS TEXT))";
            $pattern = mb_convert_case($pattern, MB_CASE_FOLD, 'UTF-8');
        }

        return $query->whereRaw("{$wrapped} {$not}LIKE ? ESCAPE '!'", [$pattern], $boolean);
    }

    /** @phpstan-assert literal-string $column */
    private function assertWrappedColumn(string $column): void
    {
        if (preg_match('/\A(?:"[A-Za-z_][A-Za-z0-9_]*"|`[A-Za-z_][A-Za-z0-9_]*`|\[[A-Za-z_][A-Za-z0-9_]*\])(?:\.(?:"[A-Za-z_][A-Za-z0-9_]*"|`[A-Za-z_][A-Za-z0-9_]*`|\[[A-Za-z_][A-Za-z0-9_]*\]))*\z/', $column) !== 1) {
            throw new InvalidArgumentException('The database grammar returned an unsafe column identifier.');
        }
    }

    private function registerSqliteFunction(SQLiteConnection $connection): void
    {
        $fold = static fn (?string $value): ?string => $value === null ? null : mb_convert_case($value, MB_CASE_FOLD, 'UTF-8');
        foreach ([$connection->getPdo(), $connection->getReadPdo()] as $pdo) {
            if (isset($this->sqliteFunctions[$pdo])) {
                continue;
            }

            $registered = $pdo instanceof Sqlite
                ? $pdo->createFunction('nvl_search_casefold', $fold, 1, Sqlite::DETERMINISTIC)
                : $pdo->sqliteCreateFunction('nvl_search_casefold', $fold, 1, PDO::SQLITE_DETERMINISTIC);

            if (! $registered) {
                throw new RuntimeException('Unable to initialize SQLite text search.');
            }

            $this->sqliteFunctions[$pdo] = true;
        }
    }
}
