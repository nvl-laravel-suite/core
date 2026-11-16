<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Auth\CanonicalActorResolver;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Tests\Fixtures\OwnedOwnershipReplacement;
use Nvl\Support\Tests\Fixtures\PostgreSqlCorePersistenceActor;
use Nvl\Support\Tests\Fixtures\UnscopedOwnershipBase;
use Nvl\Support\Tests\SupportedSchemaDatabaseTestCase;

/** Open an independent PostgreSQL session on the same disposable database. */
function postgreSqlCoreObserver(): Connection
{
    config(['database.connections.pgsql_core_observer' => config('database.connections.pgsql')]);

    return DB::connection('pgsql_core_observer');
}

it('bounds joined updateFrom writes', function (): void {
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Joined ownership writes are proven on the disposable PostgreSQL Core database.');
    }
    SupportedSchemaDatabaseTestCase::validateDatabase($connection->getDriverName(), $connection->getDatabaseName());
    Schema::create('ownership_claimed_models', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('company_id')->nullable();
        $table->string('name')->nullable();
    });
    Schema::create('ownership_claimed_tags', static function (Blueprint $table): void {
        $table->unsignedBigInteger('model_id');
        $table->string('label');
    });
    DB::table('ownership_claimed_models')->insert([
        ['id' => 1, 'company_id' => 7, 'name' => 'first'],
        ['id' => 2, 'company_id' => 8, 'name' => 'foreign'],
        ['id' => 3, 'company_id' => 7, 'name' => 'third'],
    ]);
    DB::table('ownership_claimed_tags')->insert([
        ['model_id' => 1, 'label' => 'hot'], ['model_id' => 2, 'label' => 'hot'], ['model_id' => 3, 'label' => 'cold'],
    ]);
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('test.claimed', 'test', OwnedOwnershipReplacement::class, OwnedOwnershipReplacement::ownershipDefinition()));
    app()->instance(OwnershipBoundary::class, new class implements OwnershipBoundary
    {
        public function query(Builder $query, string $resource): Builder
        {
            return $query->where($query->qualifyColumn('company_id'), 7);
        }

        public function assertRecord(Model $record, string $resource): void {}

        public function attributes(string $resource): array
        {
            return ['company_id' => 7];
        }

        public function key(string $resource, string $identity): string
        {
            return $identity;
        }
    });
    $joined = static fn (Builder $query): Builder => $query
        ->join('ownership_claimed_tags', 'ownership_claimed_tags.model_id', '=', 'ownership_claimed_models.id')
        ->where('ownership_claimed_tags.label', 'hot')
        ->orWhere('ownership_claimed_tags.label', 'cold');

    expect($joined(OwnedOwnershipReplacement::query())->updateFrom(['name' => DB::raw('ownership_claimed_tags.label')]))->toBe(2)
        ->and(fn () => $joined(UnscopedOwnershipBase::query())->updateFrom(['name' => 'unbounded']))->toThrow(OwnershipViolation::class)
        ->and(DB::table('ownership_claimed_models')->orderBy('id')->pluck('name', 'id')->all())->toBe([1 => 'hot', 2 => 'foreign', 3 => 'cold']);
});

it('holds the canonical actor lock until transaction completion', function (): void {
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Canonical actor row locks are proven on the disposable PostgreSQL Core database.');
    }
    SupportedSchemaDatabaseTestCase::validateDatabase($connection->getDriverName(), $connection->getDatabaseName());
    Schema::create('core_postgresql_actor_users', static function (Blueprint $table): void {
        $table->id();
    });
    $actor = PostgreSqlCorePersistenceActor::query()->create();
    $observer = postgreSqlCoreObserver();
    $observer->statement("set lock_timeout = '300ms'");
    $observerLocks = static fn (): bool => $observer->transaction(static fn (): bool => $observer->table('core_postgresql_actor_users')->where('id', $actor->id)->lockForUpdate()->exists());

    try {
        DB::transaction(function () use ($actor, $observerLocks): void {
            app(CanonicalActorResolver::class)->resolve($actor);
            expect($observerLocks())->toBeTrue();

            $locked = app(CanonicalActorResolver::class)->resolve($actor, lock: true);
            $started = microtime(true);
            expect($locked->getKey())->toBe($actor->getKey())
                ->and($observerLocks)->toThrow(QueryException::class, 'lock timeout')
                ->and(microtime(true) - $started)->toBeGreaterThanOrEqual(0.25);
        });

        expect($observerLocks())->toBeTrue();
    } finally {
        DB::purge('pgsql_core_observer');
    }
});
