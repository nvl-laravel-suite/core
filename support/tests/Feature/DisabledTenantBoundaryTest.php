<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Providers\TenantServiceProvider;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Contracts\TenantInstallationState;
use Nvl\Support\Tenancy\Contracts\TenantQueueContext;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Support\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Support\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Support\Tenancy\ValueObjects\TenantId;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;
use Nvl\Support\Tenancy\ValueObjects\TenantResourceDefinition;
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
