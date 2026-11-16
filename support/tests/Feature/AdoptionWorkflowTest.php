<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Adoption\AdoptionCoordinator;
use Nvl\Support\Adoption\AdoptionException;
use Nvl\Support\Adoption\AdoptionGate;
use Nvl\Support\Adoption\AdoptionProgress;
use Nvl\Support\Adoption\AdoptionRegistry;
use Nvl\Support\Adoption\AdoptionStore;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Tests\Fixtures\FakeAdoptionAdapter;

const ADOPTION_MIGRATIONS = __DIR__.'/../../database/migrations';

beforeEach(function (): void {
    FakeAdoptionAdapter::reset();
    $this->artisan('migrate', ['--path' => ADOPTION_MIGRATIONS, '--realpath' => true])->assertSuccessful();
    FakeAdoptionAdapter::seed(DB::connection());
    app(AdoptionRegistry::class)->register('fixture', FakeAdoptionAdapter::class);
});

afterEach(function (): void {
    FakeAdoptionAdapter::reset();
});

function adoptionCoordinator(): AdoptionCoordinator
{
    return new AdoptionCoordinator(app(AdoptionRegistry::class)->make('fixture', SystemActor::Background));
}

/** @return list<int> */
function adoptionTargetIds(string $step): array
{
    return DB::table(FakeAdoptionAdapter::Tables[$step][1])->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
}

/** Apply until the injected failure on the given write leaves earlier batches committed. */
function adoptionInterrupted(string $fingerprint, int $failOnWrite): string
{
    FakeAdoptionAdapter::$failOnWrite = $failOnWrite;
    try {
        adoptionCoordinator()->apply($fingerprint, 2);
        throw new RuntimeException('Expected the injected adoption failure.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Injected adoption failure.');
    } finally {
        FakeAdoptionAdapter::$failOnWrite = null;
    }

    return (string) DB::table(AdoptionStore::Runs)->value('id');
}

/** @return array<string, mixed> */
function adoptionCommand(array $arguments): array
{
    $status = Artisan::call('nvl:adopt', [...$arguments, '--format' => 'json']);

    return ['status' => $status, ...json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

it('plans the whole source graph without writing anything', function (): void {
    $plan = adoptionCoordinator()->plan();

    expect($plan->package)->toBe('fixture')
        ->and($plan->fingerprint)->toMatch('/^[a-f0-9]{64}$/')
        ->and($plan->expected)->toBe(['items' => 4, 'labels' => 2])
        ->and($plan->collisions)->toBe([])
        ->and(array_map(static fn ($step): string => $step->id, $plan->steps))->toBe(['items', 'labels'])
        ->and(DB::table(AdoptionStore::Runs)->count())->toBe(0)
        ->and(DB::table(AdoptionStore::Checkpoints)->count())->toBe(0)
        ->and(adoptionTargetIds('items'))->toBe([])
        ->and(FakeAdoptionAdapter::$writes)->toBe(0)
        ->and(FakeAdoptionAdapter::$authorized)->toBe(['plan'])
        ->and(adoptionCoordinator()->progress($plan))->toBeNull();
});

it('applies three committed batches and records only hashes, cursors and counts', function (): void {
    $plan = adoptionCoordinator()->plan();
    $progress = adoptionCoordinator()->apply($plan->fingerprint, 2);

    expect($progress->state)->toBe(AdoptionProgress::Applied)
        ->and($progress->written)->toBe(3)
        ->and($progress->complete())->toBeTrue()
        ->and($progress->steps)->toBe([
            'items' => ['cursor' => ['id' => 4], 'processed' => 4, 'expected' => 4, 'complete' => true],
            'labels' => ['cursor' => ['id' => 2], 'processed' => 2, 'expected' => 2, 'complete' => true],
        ])
        ->and(adoptionTargetIds('items'))->toBe([1, 2, 3, 4])
        ->and(adoptionTargetIds('labels'))->toBe([1, 2]);

    $run = DB::table(AdoptionStore::Runs)->sole();
    $stored = json_decode((string) $run->plan, true, flags: JSON_THROW_ON_ERROR);
    expect($run->fingerprint)->toBe($plan->fingerprint)
        ->and($run->actor_type)->toBe(SystemActor::class)
        ->and($run->actor_id)->toBe('Background')
        ->and($stored)->toHaveKeys(['protocol', 'package', 'adapter_version', 'source', 'destination', 'options_digest', 'steps', 'expected', 'collisions'])
        ->and(json_encode($stored))->not->toContain('alpha')
        ->and(DB::table(AdoptionStore::Checkpoints)->where('step', 'items')->value('output_revision'))->toBe('write-2');
});

it('resumes from the committed keyset cursor after a failed batch', function (): void {
    $fingerprint = adoptionCoordinator()->plan()->fingerprint;
    $runId = adoptionInterrupted($fingerprint, 2);

    expect(DB::table(AdoptionStore::Runs)->value('status'))->toBe(AdoptionProgress::Failed)
        ->and(adoptionTargetIds('items'))->toBe([1, 2])
        ->and(adoptionCoordinator()->progress(adoptionCoordinator()->plan())?->steps['items'])->toBe(['cursor' => ['id' => 2], 'processed' => 2, 'expected' => 4, 'complete' => false])
        ->and(fn () => adoptionCoordinator()->apply($fingerprint, 2))->toThrow(AdoptionException::class, "pass --run={$runId} to resume")
        ->and(fn () => adoptionCoordinator()->apply($fingerprint, 2, 'not-a-run'))->toThrow(AdoptionException::class, 'does not belong');

    $progress = adoptionCoordinator()->apply($fingerprint, 2, $runId);

    expect($progress->run)->toBe($runId)
        ->and($progress->written)->toBe(2)
        ->and($progress->state)->toBe(AdoptionProgress::Applied)
        ->and(adoptionTargetIds('items'))->toBe([1, 2, 3, 4])
        ->and(adoptionTargetIds('labels'))->toBe([1, 2])
        ->and(DB::table(AdoptionStore::Runs)->count())->toBe(1);
});

it('rolls back batch rows together with their checkpoint', function (): void {
    $fingerprint = adoptionCoordinator()->plan()->fingerprint;
    adoptionInterrupted($fingerprint, 1);

    expect(adoptionTargetIds('items'))->toBe([])
        ->and(DB::table(AdoptionStore::Checkpoints)->count())->toBe(0)
        ->and(DB::table(AdoptionStore::Runs)->value('status'))->toBe(AdoptionProgress::Failed);
});

it('rejects a changed or competing fingerprint for the same scope', function (): void {
    $reviewed = adoptionCoordinator()->plan()->fingerprint;
    $runId = adoptionInterrupted($reviewed, 2);
    DB::table(FakeAdoptionAdapter::Tables['items'][0])->insert(['id' => 5, 'name' => 'epsilon']);
    $competing = adoptionCoordinator()->plan()->fingerprint;

    expect($competing)->not->toBe($reviewed)
        ->and(fn () => adoptionCoordinator()->apply($reviewed, 2, $runId))->toThrow(AdoptionException::class, 'changed since review')
        ->and(fn () => adoptionCoordinator()->apply($competing, 2, $runId))->toThrow(AdoptionException::class, 'different fingerprint')
        ->and(fn () => adoptionCoordinator()->apply($competing, 2))->toThrow(AdoptionException::class, 'different fingerprint')
        ->and(DB::table(AdoptionStore::Runs)->count())->toBe(1)
        ->and(adoptionTargetIds('items'))->toBe([1, 2]);
});

it('detects drift behind the cursor instead of resuming over it', function (): void {
    $fingerprint = adoptionCoordinator()->plan()->fingerprint;
    $runId = adoptionInterrupted($fingerprint, 2);
    DB::table(FakeAdoptionAdapter::Tables['items'][1])->where('id', 1)->update(['name' => 'tampered']);

    expect(fn () => adoptionCoordinator()->apply($fingerprint, 2, $runId))->toThrow(AdoptionException::class, 'drift behind the cursor')
        ->and(adoptionTargetIds('items'))->toBe([1, 2])
        ->and(DB::table(AdoptionStore::Checkpoints)->where('step', 'items')->value('processed'))->toBe(2);
});

it('refuses collisions, open transactions and unknown adapter versions before writing', function (): void {
    $fingerprint = adoptionCoordinator()->plan()->fingerprint;
    DB::transaction(function () use ($fingerprint): void {
        expect(fn () => adoptionCoordinator()->apply($fingerprint))->toThrow(AdoptionException::class, 'outside an open transaction');
    });
    $runId = adoptionInterrupted($fingerprint, 2);
    DB::table(AdoptionStore::Runs)->update(['adapter_version' => '0']);
    expect(fn () => adoptionCoordinator()->apply($fingerprint, 2, $runId))->toThrow(AdoptionException::class, 'unknown adapter version');

    DB::table(AdoptionStore::Checkpoints)->delete();
    DB::table(AdoptionStore::Runs)->delete();
    DB::table(FakeAdoptionAdapter::Tables['items'][1])->delete();
    DB::table(FakeAdoptionAdapter::Tables['items'][1])->insert(['id' => 9, 'name' => 'foreign']);
    $colliding = adoptionCoordinator()->plan();

    expect($colliding->collisions)->toBe(['adoption_fixture_target_items [9] differs from its source row.'])
        ->and(fn () => adoptionCoordinator()->apply($colliding->fingerprint))->toThrow(AdoptionException::class, 'Adoption collision')
        ->and(DB::table(AdoptionStore::Runs)->count())->toBe(0);
});

it('activates only after a fresh passing verification', function (): void {
    $fingerprint = adoptionCoordinator()->plan()->fingerprint;
    expect(fn () => adoptionCoordinator()->verify())->toThrow(AdoptionException::class, 'apply it first');
    adoptionCoordinator()->apply($fingerprint, 2);

    expect(fn () => adoptionCoordinator()->activate($fingerprint))->toThrow(AdoptionException::class, 'requires a passed verification');

    $verification = adoptionCoordinator()->verify();
    $run = DB::table(AdoptionStore::Runs)->sole();
    expect($verification->passed)->toBeTrue()
        ->and($run->status)->toBe(AdoptionProgress::Verified)
        ->and($run->verification_digest)->toBe($verification->digest)
        ->and($run->verified_at)->not->toBeNull();

    DB::table(FakeAdoptionAdapter::Tables['labels'][1])->where('id', 2)->update(['label' => 'tampered']);
    expect(fn () => adoptionCoordinator()->activate($fingerprint))->toThrow(AdoptionException::class, 'changed since verification')
        ->and(DB::table(AdoptionStore::Runs)->value('activated_at'))->toBeNull();

    $failed = adoptionCoordinator()->verify();
    expect($failed->passed)->toBeFalse()
        ->and($failed->changed)->toBe(1)
        ->and(DB::table(AdoptionStore::Runs)->value('status'))->toBe(AdoptionProgress::Applied);

    DB::table(FakeAdoptionAdapter::Tables['labels'][1])->where('id', 2)->update(['label' => 'third']);
    expect(fn () => adoptionCoordinator()->activate($fingerprint))->toThrow(AdoptionException::class, 'requires a passed verification');
    adoptionCoordinator()->verify();
    FakeAdoptionAdapter::$authorized = [];
    $active = adoptionCoordinator()->activate($fingerprint);

    expect($active->state)->toBe(AdoptionProgress::Active)
        ->and($active->activatedAt)->not->toBeNull()
        ->and(FakeAdoptionAdapter::$authorized)->toBe(['activate', 'activate'])
        ->and(fn () => adoptionCoordinator()->apply($fingerprint, 2, $active->run))->toThrow(AdoptionException::class, 'already active');
});

it('reports an incomplete run as unverified', function (): void {
    $fingerprint = adoptionCoordinator()->plan()->fingerprint;
    adoptionInterrupted($fingerprint, 2);
    $verification = adoptionCoordinator()->verify();

    expect($verification->passed)->toBeFalse()
        ->and($verification->missing)->toBe(4)
        ->and($verification->problems)->toBe(['Adoption step [items] has not completed.', 'Adoption step [labels] has not completed.']);
});

it('authorizes every phase through the adapter', function (): void {
    FakeAdoptionAdapter::$deny = 'apply';
    $fingerprint = adoptionCoordinator()->plan()->fingerprint;

    expect(fn () => adoptionCoordinator()->apply($fingerprint))->toThrow(AuthorizationException::class)
        ->and(DB::table(AdoptionStore::Runs)->count())->toBe(0);
});

it('guards integrations until the package adoption is active', function (): void {
    $gate = app(AdoptionGate::class);
    expect($gate->isActive('fixture'))->toBeFalse()
        ->and(fn () => $gate->assertActive('fixture'))->toThrow(AdoptionException::class, 'has not been activated');

    $fingerprint = adoptionCoordinator()->plan()->fingerprint;
    adoptionCoordinator()->apply($fingerprint, 2);
    adoptionCoordinator()->verify();
    expect($gate->isActive('fixture'))->toBeFalse();

    adoptionCoordinator()->activate($fingerprint);
    $gate->assertActive('fixture');
    expect($gate->isActive('fixture'))->toBeTrue()
        ->and($gate->isActive('other'))->toBeFalse();
});

it('runs every phase through nvl:adopt as the background actor', function (): void {
    $manifest = tempnam(sys_get_temp_dir(), 'nvl-adopt-');
    file_put_contents($manifest, json_encode(['mapping' => ['b' => 2, 'a' => 1]], JSON_THROW_ON_ERROR));

    try {
        $plan = adoptionCommand(['package' => 'fixture', 'manifest' => $manifest]);
        expect($plan)->toMatchArray(['status' => 0, 'phase' => 'plan', 'run' => null, 'state' => 'planned', 'collisions' => []])
            ->and($plan['counts'])->toBe(['items' => ['expected' => 4, 'processed' => 0, 'complete' => false], 'labels' => ['expected' => 2, 'processed' => 0, 'complete' => false]])
            ->and(DB::table(AdoptionStore::Runs)->count())->toBe(0);

        expect(adoptionCommand(['package' => 'fixture', 'phase' => 'apply']))->toMatchArray(['status' => 1, 'error' => 'The apply phase requires --fingerprint from a reviewed plan.'])
            ->and(adoptionCommand(['package' => 'fixture', 'phase' => 'verify', '--run' => 'x']))->toMatchArray(['status' => 1, 'error' => 'The --run option only resumes the apply phase.'])
            ->and(adoptionCommand(['package' => 'missing']))->toMatchArray(['status' => 1, 'error' => 'No adoption adapter is registered for package [missing].']);

        FakeAdoptionAdapter::$failOnWrite = 2;
        expect(adoptionCommand(['package' => 'fixture', 'phase' => 'apply', 'manifest' => $manifest, '--fingerprint' => $plan['fingerprint'], '--batch-size' => '2']))
            ->toMatchArray(['status' => 1, 'error' => 'Injected adoption failure.']);
        FakeAdoptionAdapter::$failOnWrite = null;
        $runId = (string) DB::table(AdoptionStore::Runs)->value('id');

        $applied = adoptionCommand(['package' => 'fixture', 'phase' => 'apply', 'manifest' => $manifest, '--fingerprint' => $plan['fingerprint'], '--run' => $runId, '--batch-size' => '2']);
        expect($applied)->toMatchArray(['status' => 0, 'run' => $runId, 'fingerprint' => $plan['fingerprint'], 'state' => 'applied', 'batches' => 2, 'collisions' => []])
            ->and($applied['cursors'])->toBe(['items' => ['id' => 4], 'labels' => ['id' => 2]])
            ->and($applied['counts']['items'])->toBe(['expected' => 4, 'processed' => 4, 'complete' => true]);

        $verified = adoptionCommand(['package' => 'fixture', 'phase' => 'verify', 'manifest' => $manifest]);
        expect($verified['status'])->toBe(0)
            ->and($verified['verification'])->toMatchArray(['passed' => true, 'missing' => 0, 'extra' => 0, 'changed' => 0]);

        $active = adoptionCommand(['package' => 'fixture', 'phase' => 'activate', 'manifest' => $manifest, '--fingerprint' => $plan['fingerprint']]);
        expect($active)->toMatchArray(['status' => 0, 'run' => $runId, 'state' => 'active'])
            ->and($active['activated_at'])->not->toBeNull()
            ->and(DB::table(AdoptionStore::Runs)->value('actor_id'))->toBe('Background')
            ->and(json_decode((string) DB::table(AdoptionStore::Runs)->value('plan'), true)['options_digest'])
            ->toBe(adoptionCoordinatorWith(['mapping' => ['a' => 1, 'b' => 2]])->plan()->optionsDigest);

        $this->artisan('nvl:adopt', ['package' => 'fixture', 'manifest' => $manifest])->expectsOutputToContain('Fingerprint: '.$plan['fingerprint'])->assertSuccessful();
    } finally {
        unlink($manifest);
    }
});

it('refuses to resume a run with different options even when the fingerprint ignores them', function (): void {
    FakeAdoptionAdapter::$fingerprintIgnoresOptions = true;
    $first = adoptionCoordinatorWith(['mode' => 'a']);
    $fingerprint = $first->plan()->fingerprint;
    FakeAdoptionAdapter::$failOnWrite = 2;
    expect(fn () => $first->apply($fingerprint, 2))->toThrow(RuntimeException::class, 'Injected adoption failure.');
    FakeAdoptionAdapter::$failOnWrite = null;
    $runId = (string) DB::table(AdoptionStore::Runs)->value('id');
    $second = adoptionCoordinatorWith(['mode' => 'b']);

    expect($second->plan()->fingerprint)->toBe($fingerprint)
        ->and(fn () => $second->apply($fingerprint, 2, $runId))->toThrow(AdoptionException::class, 'different options')
        ->and(fn () => $second->verify())->toThrow(AdoptionException::class, 'different options')
        ->and(adoptionTargetIds('items'))->toBe([1, 2])
        ->and(adoptionCoordinatorWith(['mode' => 'a'])->apply($fingerprint, 2, $runId)->state)->toBe(AdoptionProgress::Applied);
});

it('binds the fixture fingerprint to its options', function (): void {
    expect(adoptionCoordinatorWith(['mode' => 'a'])->plan()->fingerprint)
        ->not->toBe(adoptionCoordinatorWith(['mode' => 'b'])->plan()->fingerprint);
});

it('rolls back batch rows when the run update fails after writeBatch returns', function (): void {
    $fingerprint = adoptionCoordinator()->plan()->fingerprint;
    $armed = true;
    DB::connection()->beforeExecuting(function (string $query) use (&$armed): void {
        if ($armed && FakeAdoptionAdapter::$writes === 2 && str_starts_with(strtolower($query), 'update') && str_contains($query, AdoptionStore::Runs)) {
            $armed = false;
            throw new RuntimeException('Injected run update failure.');
        }
    });

    expect(fn () => adoptionCoordinator()->apply($fingerprint, 2))->toThrow(RuntimeException::class, 'Injected run update failure.')
        ->and(FakeAdoptionAdapter::$writes)->toBe(2)
        ->and(adoptionTargetIds('items'))->toBe([1, 2])
        ->and(DB::table(AdoptionStore::Checkpoints)->where('step', 'items')->value('processed'))->toBe(2)
        ->and(DB::table(AdoptionStore::Runs)->value('status'))->toBe(AdoptionProgress::Failed);
});

it('wraps every batch transaction in the adapter batch hook, also when it fails', function (): void {
    $fingerprint = adoptionCoordinator()->plan()->fingerprint;
    adoptionInterrupted($fingerprint, 2);

    expect(FakeAdoptionAdapter::$batchHooks)->toBe(['enter', 'exit', 'enter', 'exit']);

    FakeAdoptionAdapter::$batchHooks = [];
    $progress = adoptionCoordinator()->apply($fingerprint, 2, (string) DB::table(AdoptionStore::Runs)->value('id'));

    expect($progress->written)->toBe(2)
        ->and(FakeAdoptionAdapter::$batchHooks)->toBe(array_merge(...array_fill(0, 4, ['enter', 'exit'])));
});

it('reports the run and state when nvl:adopt apply fails', function (): void {
    $fingerprint = adoptionCoordinator()->plan()->fingerprint;
    FakeAdoptionAdapter::$failOnWrite = 2;
    $failed = adoptionCommand(['package' => 'fixture', 'phase' => 'apply', '--fingerprint' => $fingerprint, '--batch-size' => '2']);

    expect($failed)->toMatchArray([
        'status' => 1,
        'error' => 'Injected adoption failure.',
        'run' => DB::table(AdoptionStore::Runs)->value('id'),
        'state' => AdoptionProgress::Failed,
    ]);
});

it('loads the adoption migration only when enabled and publishes it under nvl-core-migrations', function (): void {
    $path = realpath(ADOPTION_MIGRATIONS);
    expect(array_map('realpath', app('migrator')->paths()))->not->toContain($path)
        ->and(array_map('realpath', array_keys(SupportServiceProvider::pathsToPublish(SupportServiceProvider::class, 'nvl-core-migrations'))))->toBe([$path]);

    config()->set('nvl-core.adoption.migrations.enabled', true);
    $this->app->register(SupportServiceProvider::class, true);

    expect(array_map('realpath', app('migrator')->paths()))->toContain($path);
});

function adoptionCoordinatorWith(array $options): AdoptionCoordinator
{
    return new AdoptionCoordinator(app(AdoptionRegistry::class)->make('fixture', SystemActor::Background, $options));
}
