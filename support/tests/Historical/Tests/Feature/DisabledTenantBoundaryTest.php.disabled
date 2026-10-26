<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Providers\TenantServiceProvider;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Contracts\TenantInstallationState;
use Nvl\Support\Tenancy\Contracts\TenantParentResolver;
use Nvl\Support\Tenancy\Contracts\TenantQueueContext;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Support\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Support\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Support\Tenancy\Services\DisabledTenantBoundary;
use Nvl\Support\Tenancy\Services\DisabledTenantOwnershipConfiguration;
use Nvl\Support\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Support\Tenancy\ValueObjects\TenantId;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;
use Nvl\Support\Tenancy\ValueObjects\TenantResourceDefinition;
use Nvl\Support\Tests\Fixtures\DisabledBoundaryCanonicalRecord;
use Nvl\Support\Tests\Fixtures\DisabledBoundaryChildRecord;
use Nvl\Support\Tests\Fixtures\DisabledBoundaryRecord;

beforeEach(function (): void {
    $this->app->register(TenantServiceProvider::class);
    $this->app->make(TenantResourceRegistry::class)->register(new TenantResourceDefinition('fixture.records', 'fixture', DisabledBoundaryRecord::class));
});

it('keeps validated legacy identities and queries unchanged without tenant runtime', function (): void {
    $boundary = $this->app->make(TenantBoundary::class);
    $query = DisabledBoundaryRecord::query()->where('name', 'visible');
    expect($boundary->query($query, 'fixture.records'))->toBe($query)
        ->and($boundary->attributes('fixture.records'))->toBe([])
        ->and($boundary->key('fixture.records', 'original'))->toBe('original')
        ->and($this->app->make(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Disabled);
    expect(fn () => $this->app->make(TenantContext::class)->requireTenant())->toThrow(TenantContextMissing::class);
});

it('rejects enabled tenancy without the runtime', function (): void {
    config()->set('nvl-tenancy.enabled', true);
    expect(fn () => $this->app->make(TenantBoundary::class)->attributes('fixture.records'))->toThrow(TenantConfigurationInvalid::class);
});

it('rejects persisted adoption before legacy queries and queued callbacks', function (): void {
    Schema::create(PackageStorage::resolveTable('tenancy', 'installation_state'), function (Blueprint $table): void {
        $table->string('resource');
    });
    DisabledBoundaryRecord::resolveConnection()->table(PackageStorage::resolveTable('tenancy', 'installation_state'))->insert(['resource' => 'fixture.records']);
    expect(fn () => $this->app->make(TenantBoundary::class)->query(DisabledBoundaryRecord::query(), 'fixture.records'))->toThrow(TenantSchemaNotReady::class);
    expect(fn () => $this->app->make(TenantQueueContext::class)->run(new TenantJobEnvelope(new TenantContextSnapshot(TenantContextMode::Disabled)), fn (): string => 'unsafe'))->toThrow(TenantSchemaNotReady::class);
});

it('rejects tenant envelopes without invoking queued work', function (): void {
    $called = false;
    $envelope = new TenantJobEnvelope(new TenantContextSnapshot(TenantContextMode::Tenant, new TenantId('01954c28-56ec-7bda-9721-e5c05d7e1a54')));
    expect(fn () => $this->app->make(TenantQueueContext::class)->run($envelope, function () use (&$called): void {
        $called = true;
    }))->toThrow(TenantBoundaryViolation::class);
    expect($called)->toBeFalse();
});

it('bounds adoption probes and refreshes retained boundaries after worker scope changes', function (): void {
    $boundary = $this->app->make(TenantBoundary::class);
    $connection = DisabledBoundaryRecord::resolveConnection();
    $connection->enableQueryLog();
    $boundary->attributes('fixture.records');
    $firstProbeCount = count($connection->getQueryLog());
    $boundary->attributes('fixture.records');
    $boundary->key('fixture.records', 'identity');
    expect(count($connection->getQueryLog()))->toBe($firstProbeCount);

    Schema::create(PackageStorage::resolveTable('tenancy', 'installation_state'), function (Blueprint $table): void {
        $table->string('resource');
    });
    $connection->table(PackageStorage::resolveTable('tenancy', 'installation_state'))->insert(['resource' => 'fixture.records']);
    $this->app->forgetScopedInstances();
    expect(fn () => $boundary->attributes('fixture.records'))->toThrow(TenantSchemaNotReady::class);

    $connection->table(PackageStorage::resolveTable('tenancy', 'installation_state'))->delete();
    $this->app->make(TenantInstallationState::class)->invalidate();
    expect($boundary->attributes('fixture.records'))->toBe([]);
});

it('rejects replaced SQL sources and unions even while tenancy is disabled', function (string $shape): void {
    $query = DisabledBoundaryRecord::query();
    if ($shape === 'union') {
        $query->union($query->getModel()->getConnection()->table('private_records'));
    } else {
        $query->from('private_records');
    }
    expect(fn () => $this->app->make(TenantBoundary::class)->query($query, 'fixture.records'))->toThrow(TenantBoundaryViolation::class);
})->with(['replacement', 'union']);

it('admits same-storage model lineage through an explicit legacy resource without optional runtime', function (): void {
    $this->app->make(TenantResourceRegistry::class)->register(new TenantResourceDefinition('fixture.lineage', 'fixture', DisabledBoundaryCanonicalRecord::class));
    Schema::create('disabled_boundary_records', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    DB::table('disabled_boundary_records')->insert(['id' => 1, 'name' => 'Visible']);
    $boundary = $this->app->make(TenantBoundary::class);
    $query = DisabledBoundaryChildRecord::query()->select(['id', 'name'])->where('name', 'Visible');

    expect($boundary)->toBeInstanceOf(DisabledTenantBoundary::class);
    expect(fn () => $boundary->query($query, 'fixture.lineage'))->not->toThrow(TenantBoundaryViolation::class);
    expect($boundary->query($query, 'fixture.lineage'))->toBe($query)
        ->and($query->get()->map(static fn (DisabledBoundaryChildRecord $record): array => $record->getAttributes())->all())
        ->toBe([['id' => 1, 'name' => 'Visible']]);
    $boundary->assertRecord(new DisabledBoundaryChildRecord, 'fixture.lineage');

    Schema::create(PackageStorage::resolveTable('tenancy', 'installation_state'), static function (Blueprint $table): void {
        $table->string('resource');
    });
    DB::table(PackageStorage::resolveTable('tenancy', 'installation_state'))->insert(['resource' => 'fixture.lineage']);
    $this->app->make(TenantInstallationState::class)->invalidate();
    expect(fn () => $boundary->query(DisabledBoundaryChildRecord::query(), 'fixture.lineage'))->toThrow(TenantSchemaNotReady::class);
});

it('rejects forged lineage storage through explicit legacy resources', function (string $forgery): void {
    $this->app->make(TenantResourceRegistry::class)->register(new TenantResourceDefinition('fixture.lineage', 'fixture', DisabledBoundaryCanonicalRecord::class));
    $owner = $forgery === 'unrelated-model' ? new DisabledBoundaryRecord : new DisabledBoundaryChildRecord;
    if ($forgery === 'model-table') {
        $owner->setTable('private_records');
    }
    if (in_array($forgery, ['model-connection', 'actual-connection'], true)) {
        config(['database.connections.lineage_foreign' => config('database.connections.testing')]);
    }
    if ($forgery === 'model-connection') {
        $owner->setConnection('lineage_foreign');
    }
    $query = $owner->newQuery();
    if ($forgery === 'actual-connection') {
        $query->setQuery(DB::connection('lineage_foreign')->table('disabled_boundary_records'));
    }
    if ($forgery === 'actual-from') {
        $query->from('private_records');
    }
    if ($forgery === 'union') {
        $query->union($owner->getConnection()->table('private_records'));
    }

    expect(fn () => $this->app->make(TenantBoundary::class)->query($query, 'fixture.lineage'))->toThrow(TenantBoundaryViolation::class);
})->with(['unrelated-model', 'model-table', 'model-connection', 'actual-connection', 'actual-from', 'union']);

it('requires an exact host registration despite explicit package resource lineage', function (): void {
    $resources = $this->app->make(TenantResourceRegistry::class);
    $resources->register(new TenantResourceDefinition('fixture.lineage', 'fixture', DisabledBoundaryCanonicalRecord::class));

    expect(fn () => $resources->forModel(new DisabledBoundaryChildRecord))->toThrow(TenantConfigurationInvalid::class);
});

it('compares effective connection instances and inventories participating host transactions', function (): void {
    config(['database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $connections = app(EffectiveTenantConnection::class);
    $default = DB::connection();
    expect($connections->name(null))->toBe($default->getName());
    $connections->assertCompatible([null, $default->getName()]);
    expect(fn () => $connections->assertCompatible([null, 'other']))->toThrow(TenantConfigurationInvalid::class);
    expect($connections->participating())->toContain($default, DB::connection('other'));
    config(['nvl-tenancy.connection' => 'other']);
    expect($connections->core())->toBe(DB::connection('other'));
    config(['nvl-tenancy.connection' => false]);
    expect(fn () => $connections->core())->toThrow(TenantConfigurationInvalid::class);
});

it('validates disabled canonical parent allowlists against registered model identities', function (string $shape): void {
    $resources = app(TenantResourceRegistry::class);
    $resolver = new class implements TenantParentResolver
    {
        /** @var array<string, class-string<Model>> */
        public array $parents = [];

        public function types(): array
        {
            return $this->parents;
        }
    };
    $resolver->parents = match ($shape) {
        'empty alias' => ['' => DisabledBoundaryRecord::class],
        'unregistered model' => ['owner' => DisabledBoundaryCanonicalRecord::class],
        default => ['owner' => DisabledBoundaryRecord::class],
    };
    $resources->registerParentResolver('fixture.records', $resolver::class);
    app()->instance($resolver::class, $shape === 'wrong binding' ? new stdClass : $resolver);
    $ownership = app(DisabledTenantOwnershipConfiguration::class);
    if ($shape === 'valid') {
        expect($ownership->parentTypes('fixture.records'))->toBe(['owner' => DisabledBoundaryRecord::class]);
    } else {
        expect(fn () => $ownership->parentTypes('fixture.records'))->toThrow(TenantConfigurationInvalid::class);
    }
})->with(['valid', 'empty alias', 'unregistered model', 'wrong binding']);
