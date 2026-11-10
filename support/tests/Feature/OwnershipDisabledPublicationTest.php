<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipQueuePublication;
use Nvl\Support\Ownership\Services\OwnershipStorageTables;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;
use Nvl\Support\Tests\Fixtures\OwnedOwnershipReplacement;
use Nvl\Support\Tests\Fixtures\OwnershipQueueProbe;
use Nvl\Support\Tests\Fixtures\PublicationJob;
use Nvl\Support\Tests\Fixtures\PublicationResource;
use Nvl\Support\Tests\Fixtures\PublicationScenario;

beforeEach(function (): void {
    PublicationScenario::install();
    OwnershipQueuePublication::register();
});

it('probes each actual source once for every cold and warm captured publication', function (int $resources): void {
    PublicationScenario::register($resources, distinctTables: true);
    $catalogs = 0;
    DB::listen(static function (QueryExecuted $query) use (&$catalogs): void {
        if (str_contains($query->sql, ' as protocol')) {
            $catalogs++;
        }
    });
    $queue = Queue::connection('database');
    $counts = [];
    foreach (['first', 'warm'] as $identity) {
        $catalogs = 0;
        $started = hrtime(true);
        $job = new PublicationJob($identity);
        $queue->push($job);
        $counts[] = $catalogs;
        $path = getenv('NVL_PUBLICATION_METRICS_PATH');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode(['publication' => $identity, 'resources' => $resources, 'connections' => 1, 'tables' => $resources, 'catalogs' => $catalogs, 'elapsed_ms' => (hrtime(true) - $started) / 1_000_000], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
    $payloads = DB::table('jobs')->orderBy('id')->pluck('payload')->map(static fn (string $wire): array => json_decode($wire, true, flags: JSON_THROW_ON_ERROR));
    expect($payloads)->toHaveCount(2)
        ->and($payloads->pluck('data.nvl_ownership.mode')->all())->toBe(['disabled', 'disabled'])
        ->and($payloads->pluck('uuid')->unique())->toHaveCount(2)
        ->and(DB::table('publication_events')->count())->toBe(0)
        ->and($counts)->toBe([1, 1]);
})->with([1, 32, 189]);

it('deduplicates marker inventory and partition inspection without dropping resource aliases', function (): void {
    PublicationScenario::register(32);
    PublicationScenario::markers(DB::connection());
    $counts = ['catalog' => 0, 'markers' => 0, 'partition' => 0];
    DB::listen(static function (QueryExecuted $query) use (&$counts): void {
        if (str_contains($query->sql, ' as protocol')) {
            $counts['catalog']++;
        } elseif (str_contains($query->sql, 'select * from') && str_contains($query->sql, OwnershipStorageTables::Markers)) {
            $counts['markers']++;
        } elseif (str_contains($query->sql, 'pragma_table_xinfo') || str_contains($query->sql, 'pg_attribute') || str_contains($query->sql, 'information_schema.columns')) {
            $counts['partition']++;
        }
    });
    Queue::connection('database')->push(new PublicationJob('aliases'));
    expect(DB::table('jobs')->count())->toBe(1)
        ->and($counts)->toBe(['catalog' => 1, 'markers' => 1, 'partition' => 1]);
});

it('refuses every matching or malformed persisted marker before inserting a job', function (array $marker): void {
    PublicationScenario::register(32);
    PublicationScenario::markers(DB::connection(), [$marker]);
    expect(fn () => Queue::connection('database')->push(new PublicationJob('denied')))->toThrow(OwnershipSchemaNotReady::class)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('publication_events')->count())->toBe(0);
})->with([
    'later resource alias only' => [['resource' => 'publication.record_32', 'physical_table' => 'old_name', 'state' => 'prepared']],
    'physical table only' => [['resource' => 'retired.resource', 'physical_table' => 'publication_records', 'state' => 'active']],
    'both identities with unknown state' => [['resource' => 'publication.record_1', 'physical_table' => 'publication_records', 'state' => 'unknown']],
    'matching identity without state or generation' => [['resource' => 'publication.record_32', 'physical_table' => 'old_name']],
    'missing resource value' => [['physical_table' => 'unrelated']],
    'missing table value' => [['resource' => 'unrelated']],
]);

it('allows only well formed unrelated markers while inspecting every actual source', function (bool $protected): void {
    $second = PublicationScenario::secondSource();
    PublicationScenario::register(32, $second);
    PublicationScenario::markers($second, [['resource' => $protected ? 'publication.record_32' : 'unrelated.resource', 'physical_table' => 'old_name']]);
    $sources = [];
    DB::listen(static function (QueryExecuted $query) use (&$sources): void {
        if (str_contains($query->sql, ' as protocol')) {
            $sources[] = spl_object_id($query->connection);
        }
    });
    expect($second)->not->toBe(DB::connection())
        ->and($second->getName())->toBe(DB::connection()->getName())
        ->and($second->getDatabaseName())->toBe(DB::connection()->getDatabaseName());
    $publish = fn () => Queue::connection('database')->push(new PublicationJob('two-sources'));
    if ($protected) {
        expect($publish)->toThrow(OwnershipSchemaNotReady::class);
    } else {
        $publish();
    }
    expect($sources)->toHaveCount(2)
        ->and(array_unique($sources))->toHaveCount(2)
        ->and(DB::table('jobs')->count())->toBe($protected ? 0 : 1);
})->with(['both safe' => false, 'second protected' => true]);

it('uses the write source when a replica has no ownership catalog', function (): void {
    PublicationScenario::register(32);
    $replica = PublicationScenario::secondSource();
    PublicationScenario::markers(DB::connection(), [['resource' => 'publication.record_32', 'physical_table' => 'old_name']]);
    $source = DB::connection();
    $source->setReadPdo($replica->getPdo());
    $source->forgetRecordModificationState();
    expect($source->getReadPdo())->not->toBe($source->getPdo())
        ->and(fn () => Queue::connection('database')->push(new PublicationJob('replica-denied')))->toThrow(OwnershipSchemaNotReady::class)
        ->and(DB::table('jobs')->useWritePdo()->count())->toBe(0);
});

it('rechecks storage after a successful publication instead of caching permission', function (bool $partition): void {
    PublicationScenario::register(32);
    Queue::connection('database')->push(new PublicationJob('first'));
    PublicationScenario::markers(DB::connection(), $partition ? [] : [['resource' => 'publication.record_32', 'physical_table' => 'old_name']]);
    if ($partition) {
        DB::getSchemaBuilder()->rename(OwnershipStorageTables::Markers, 'previous_'.OwnershipStorageTables::Markers);
        DB::getSchemaBuilder()->table('publication_records', static fn (Blueprint $table) => $table->string('owner_scope_key')->nullable());
    }
    expect(fn () => Queue::connection('database')->push(new PublicationJob('second')))->toThrow(OwnershipSchemaNotReady::class)
        ->and(DB::table('jobs')->count())->toBe(1);
})->with(['new marker' => false, 'partition with renamed protocol' => true]);

it('propagates catalog and marker failures before persistence or callbacks', function (bool $marker): void {
    PublicationScenario::register(32);
    if ($marker) {
        PublicationScenario::markers(DB::connection());
    }
    DB::connection()->beforeExecuting(static function (string $query) use ($marker): void {
        if ((! $marker && str_contains($query, ' as protocol')) || ($marker && str_contains($query, 'select * from') && str_contains($query, OwnershipStorageTables::Markers))) {
            throw new RuntimeException('Admission source read failed.');
        }
    });
    expect(fn () => Queue::connection('database')->push(new PublicationJob('failed-read')))->toThrow(RuntimeException::class, 'Admission source read failed.')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('publication_events')->count())->toBe(0);
})->with(['catalog failure' => false, 'marker failure' => true]);

it('rechecks nested admission and never invokes a refused callback', function (): void {
    PublicationScenario::register(32);
    $context = app(OwnershipQueueContext::class);
    $envelope = OwnershipJobEnvelope::fromSnapshot(new OwnershipSnapshot(OwnershipContextMode::Disabled));
    $invoked = false;
    $context->run($envelope, function () use ($context, $envelope, &$invoked): void {
        PublicationScenario::markers(DB::connection(), [['resource' => 'publication.record_32', 'physical_table' => 'old_name']]);
        expect(fn () => $context->run($envelope, function () use (&$invoked): void {
            $invoked = true;
        }))->toThrow(OwnershipSchemaNotReady::class);
    });
    expect($invoked)->toBeFalse();
});

it('rejects declared ownership before probing any unscoped resource', function (): void {
    $resources = PublicationScenario::register(32);
    $command = new PublicationJob('stale-disabled-capture');
    $resources->register(new OwnershipResourceDefinition('publication.owned', 'publication', OwnedOwnershipReplacement::class, OwnedOwnershipReplacement::ownershipDefinition()));
    $catalogs = 0;
    DB::listen(static function (QueryExecuted $query) use (&$catalogs): void {
        if (str_contains($query->sql, ' as protocol')) {
            $catalogs++;
        }
    });
    expect(fn () => Queue::connection('database')->push($command))->toThrow(OwnershipViolation::class)
        ->and($catalogs)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('preflights every declared strategy before admitting Core-only batch callbacks', function (): void {
    $resources = PublicationScenario::register(32);
    $resources->register(new OwnershipResourceDefinition('publication.owned', 'publication', OwnedOwnershipReplacement::class, OwnedOwnershipReplacement::ownershipDefinition()));
    $catalogs = 0;
    DB::listen(static function (QueryExecuted $query) use (&$catalogs): void {
        if (str_contains($query->sql, ' as protocol')) {
            $catalogs++;
        }
    });
    expect(fn () => app(OwnershipQueueContext::class)->captureBatch(Bus::batch([])))->toThrow(OwnershipViolation::class)
        ->and($catalogs)->toBe(0);
});

it('leaves uncaptured jobs and nonobject payloads outside the catalog hook', function (): void {
    PublicationScenario::register(32);
    $catalogs = 0;
    DB::listen(static function (QueryExecuted $query) use (&$catalogs): void {
        if (str_contains($query->sql, ' as protocol')) {
            $catalogs++;
        }
    });
    Queue::connection('database')->push(new OwnershipQueueProbe);
    expect(OwnershipQueuePublication::payload(payload: ['data' => ['command' => 'serialized command']]))->toBe([])
        ->and($catalogs)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('preserves outer commit rollback and first replay warm publication behavior', function (string $driver): void {
    PublicationScenario::register(32);
    $create = function (int $identity) use ($driver): int {
        return DB::transaction(function () use ($identity, $driver): int {
            if (DB::table('publication_records')->where('id', $identity)->exists()) {
                return $identity;
            }
            DB::table('publication_records')->insert(['id' => $identity]);
            Queue::connection($driver)->push((new PublicationJob('record-'.$identity))->afterCommit());

            return $identity;
        });
    };
    DB::beginTransaction();
    $first = $create(1);
    expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('publication_events')->count())->toBe(0);
    DB::commit();
    expect([$first, $create(1), $create(2)])->toBe([1, 1, 2])
        ->and(DB::table('publication_records')->orderBy('id')->pluck('id')->all())->toBe([1, 2]);
    if ($driver === 'database') {
        expect(DB::table('jobs')->count())->toBe(2)->and(DB::table('publication_events')->count())->toBe(0);
        $queue = Queue::connection('database');
        foreach ([1, 2] as $unused) {
            app('queue.worker')->process('database', $queue->pop(), new WorkerOptions(maxTries: 1));
        }
        expect(DB::table('jobs')->count())->toBe(0);
    }
    expect(DB::table('publication_events')->orderBy('identity')->pluck('identity')->all())->toBe(['record-1', 'record-2'])
        ->and(DB::table('publication_events')->pluck('mode')->unique()->all())->toBe(['disabled']);
    DB::beginTransaction();
    $create(3);
    DB::rollBack();
    expect(DB::table('publication_records')->where('id', 3)->exists())->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('publication_events')->count())->toBe(2);
})->with(['database', 'sync']);

it('refuses malformed unrelated marker identities even when a catalog column is missing', function (string $malformation): void {
    PublicationScenario::register(32);
    DB::getSchemaBuilder()->create(OwnershipStorageTables::Markers, static function (Blueprint $table) use ($malformation): void {
        if ($malformation === 'numeric resource') {
            $table->integer('resource');
        } elseif ($malformation !== 'missing resource') {
            $table->string('resource');
        }
        if ($malformation !== 'missing table') {
            $table->string('physical_table');
        }
    });
    $marker = match ($malformation) {
        'numeric resource' => ['resource' => 7, 'physical_table' => 'unrelated'],
        'missing resource' => ['physical_table' => 'unrelated'],
        default => ['resource' => 'unrelated'],
    };
    DB::table(OwnershipStorageTables::Markers)->insert($marker);
    expect(fn () => Queue::connection('database')->push(new PublicationJob('malformed')))->toThrow(OwnershipSchemaNotReady::class)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(['numeric resource', 'missing resource', 'missing table']);

it('preserves prefixed marker and renamed protocol partition refusal', function (bool $renamed): void {
    $resources = PublicationScenario::register(32);
    config(['database.connections.publication_prefixed' => [...DB::connection()->getConfig(), 'prefix' => 'publication_', 'url' => null]]);
    $source = DB::connection('publication_prefixed');
    $source->getSchemaBuilder()->create('publication_records', static function (Blueprint $table) use ($renamed): void {
        $table->id();
        if ($renamed) {
            $table->string('owner_scope_key')->nullable();
        }
    });
    PublicationScenario::markers($source, [['resource' => 'publication.record_32', 'physical_table' => 'old_name']]);
    if ($renamed) {
        $source->getSchemaBuilder()->rename(OwnershipStorageTables::Markers, 'retired_'.OwnershipStorageTables::Markers);
    }
    foreach ($resources->all() as $resource) {
        PublicationResource::$sources[$resource->model] = $source;
    }
    expect(fn () => Queue::connection('database')->push(new PublicationJob('prefixed')))->toThrow(OwnershipSchemaNotReady::class)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(['current prefix' => false, 'renamed prefixed protocol' => true]);

it('observes changed PostgreSQL search paths and surviving physical partitions', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires the explicit PostgreSQL publication lane.');
    }
    PublicationScenario::register(32);
    $source = DB::connection();
    PublicationScenario::markers($source);
    $source->getSchemaBuilder()->rename(OwnershipStorageTables::Markers, 'retired_'.OwnershipStorageTables::Markers);
    $source->statement('CREATE SCHEMA publication_shadow');
    try {
        $source->statement('CREATE TABLE publication_shadow.publication_records (id bigint PRIMARY KEY, owner_scope_key varchar(191))');
        Queue::connection('database')->push(new PublicationJob('before-search-change'));
        $source->statement('SET search_path TO publication_shadow, public');
        expect(fn () => Queue::connection('database')->push(new PublicationJob('after-search-change')))->toThrow(OwnershipSchemaNotReady::class)
            ->and(DB::table('jobs')->count())->toBe(1);
    } finally {
        $source->statement('SET search_path TO public');
        $source->statement('DROP SCHEMA publication_shadow CASCADE');
    }
});
