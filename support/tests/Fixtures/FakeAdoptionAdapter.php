<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Adoption\AdoptionAdapter;
use Nvl\Support\Adoption\AdoptionBatch;
use Nvl\Support\Adoption\AdoptionPlan;
use Nvl\Support\Adoption\AdoptionStep;
use Nvl\Support\Adoption\AdoptionVerification;
use RuntimeException;

/**
 * Adopts two committed source tables into two destination tables: items, then labels that depend on items.
 *
 * Four items and two labels at batch size two give three batches.
 */
final class FakeAdoptionAdapter extends AdoptionAdapter
{
    public const array Tables = [
        'items' => ['adoption_fixture_source_items', 'adoption_fixture_target_items'],
        'labels' => ['adoption_fixture_source_labels', 'adoption_fixture_target_labels'],
    ];

    /** Fail after writing the rows of this 1-based writeBatch call. */
    public static ?int $failOnWrite = null;

    public static int $writes = 0;

    public static int $delayMicroseconds = 0;

    public static ?string $deny = null;

    /** @var list<string> */
    public static array $authorized = [];

    /** Emulate an adapter whose fingerprint wrongly leaves its options out. */
    public static bool $fingerprintIgnoresOptions = false;

    /** @var list<string> */
    public static array $batchHooks = [];

    /** Race fixture: on the first write, while the run row is locked, create this file and wait for the continue file. */
    public static ?string $pauseFile = null;

    public static ?string $continueFile = null;

    public static function reset(): void
    {
        self::$failOnWrite = null;
        self::$writes = 0;
        self::$delayMicroseconds = 0;
        self::$deny = null;
        self::$authorized = [];
        self::$fingerprintIgnoresOptions = false;
        self::$batchHooks = [];
        self::$pauseFile = null;
        self::$continueFile = null;
    }

    /** Create and seed the committed source and empty destination tables. */
    public static function seed(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();
        foreach (self::Tables as [$source, $target]) {
            $schema->dropIfExists($source);
            $schema->dropIfExists($target);
        }
        foreach (['items' => 'name', 'labels' => 'label'] as $step => $column) {
            foreach (self::Tables[$step] as $table) {
                $schema->create($table, static function (Blueprint $table) use ($column, $step): void {
                    $table->unsignedBigInteger('id')->primary();
                    if ($step === 'labels') {
                        $table->unsignedBigInteger('item_id');
                    }
                    $table->string($column);
                });
            }
        }
        $connection->table(self::Tables['items'][0])->insert([
            ['id' => 1, 'name' => 'alpha'], ['id' => 2, 'name' => 'beta'], ['id' => 3, 'name' => 'gamma'], ['id' => 4, 'name' => 'delta'],
        ]);
        $connection->table(self::Tables['labels'][0])->insert([
            ['id' => 1, 'item_id' => 1, 'label' => 'first'], ['id' => 2, 'item_id' => 3, 'label' => 'third'],
        ]);
    }

    public function connection(): Connection
    {
        $connection = DB::connection();

        return $connection instanceof Connection ? $connection : throw new RuntimeException('The fixture requires a database connection.');
    }

    public function authorize(string $phase): void
    {
        self::$authorized[] = $phase;
        if (self::$deny === $phase) {
            throw new AuthorizationException("Denied {$phase}.");
        }
    }

    public function plan(): AdoptionPlan
    {
        $source = $this->sourceRows();
        $collisions = [];
        foreach (self::Tables as $step => [, $target]) {
            foreach ($this->rows($target) as $id => $row) {
                if (($source[$step][$id] ?? null) !== $row) {
                    $collisions[] = "{$target} [{$id}] differs from its source row.";
                }
            }
        }

        return new AdoptionPlan(
            package: 'fixture',
            adapterVersion: '1',
            fingerprint: AdoptionPlan::digest(['mapping' => 1, 'rows' => $source, 'options' => self::$fingerprintIgnoresOptions ? [] : $this->options]),
            source: ['items' => self::Tables['items'][0], 'labels' => self::Tables['labels'][0]],
            destination: ['items' => self::Tables['items'][1], 'labels' => self::Tables['labels'][1]],
            optionsDigest: AdoptionPlan::digest($this->options),
            steps: [new AdoptionStep('items'), new AdoptionStep('labels', ['items'])],
            expected: ['items' => count($source['items']), 'labels' => count($source['labels'])],
            collisions: $collisions,
        );
    }

    public function aroundBatch(Closure $batch): mixed
    {
        self::$batchHooks[] = 'enter';
        try {
            return $batch();
        } finally {
            self::$batchHooks[] = 'exit';
        }
    }

    public function nextBatch(AdoptionStep $step, ?array $cursor, int $limit): AdoptionBatch
    {
        $connection = $this->connection();
        $rows = $step->page($connection->table(self::Tables[$step->id][0])->useWritePdo(), $cursor, $limit + 1)->get()->all();
        $page = array_slice($rows, 0, $limit);
        $last = $page === [] ? null : $page[array_key_last($page)];

        return new AdoptionBatch(
            $step->id,
            $cursor,
            $last === null ? $cursor : $step->cursorFrom($last),
            array_map(static fn (object $row): array => get_object_vars($row), $page),
            count($rows) <= $limit,
        );
    }

    public function writeBatch(AdoptionBatch $batch): ?string
    {
        self::$writes++;
        if (self::$pauseFile !== null && self::$continueFile !== null) {
            $continue = self::$continueFile;
            touch(self::$pauseFile);
            self::$pauseFile = self::$continueFile = null;
            $deadline = microtime(true) + 20;
            while (! is_file($continue)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('The adoption race continue barrier timed out.');
                }
                usleep(5000);
                clearstatcache();
            }
        }
        if (self::$delayMicroseconds > 0) {
            usleep(self::$delayMicroseconds);
        }
        $this->connection()->table(self::Tables[$batch->step][1])->insert($batch->rows);
        if (self::$failOnWrite === self::$writes) {
            throw new RuntimeException('Injected adoption failure.');
        }

        return 'write-'.self::$writes;
    }

    public function verify(AdoptionPlan $plan): AdoptionVerification
    {
        $source = $this->sourceRows();
        $missing = $extra = $changed = 0;
        $targets = [];
        foreach (self::Tables as $step => [, $target]) {
            $targets[$step] = $this->rows($target);
            foreach ($source[$step] as $id => $row) {
                if (! isset($targets[$step][$id])) {
                    $missing++;
                } elseif ($targets[$step][$id] !== $row) {
                    $changed++;
                }
            }
            $extra += count(array_diff_key($targets[$step], $source[$step]));
        }

        return new AdoptionVerification($missing, $extra, $changed, AdoptionPlan::digest($targets));
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, labels: array<int, array<string, mixed>>}
     */
    private function sourceRows(): array
    {
        return ['items' => $this->rows(self::Tables['items'][0]), 'labels' => $this->rows(self::Tables['labels'][0])];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $table): array
    {
        $rows = [];
        foreach ($this->connection()->table($table)->useWritePdo()->orderBy('id')->get() as $row) {
            $values = get_object_vars($row);
            $rows[(int) $values['id']] = array_map(static fn (mixed $value): mixed => is_numeric($value) ? (int) $value : $value, $values);
        }

        return $rows;
    }
}
