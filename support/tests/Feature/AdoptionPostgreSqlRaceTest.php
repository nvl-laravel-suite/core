<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nvl\Support\Adoption\AdoptionCoordinator;
use Nvl\Support\Adoption\AdoptionProgress;
use Nvl\Support\Adoption\AdoptionRegistry;
use Nvl\Support\Adoption\AdoptionStore;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Tests\Fixtures\FakeAdoptionAdapter;
use Nvl\Support\Tests\SupportedSchemaDatabaseTestCase;
use Symfony\Component\Process\Process;

it('lets two independent PostgreSQL runners resume one run without writing a batch twice', function (): void {
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The two-runner adoption race requires the disposable PostgreSQL Core database.');
    }
    SupportedSchemaDatabaseTestCase::validateDatabase($connection->getDriverName(), $connection->getDatabaseName());
    FakeAdoptionAdapter::reset();
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations', '--realpath' => true])->assertSuccessful();
    FakeAdoptionAdapter::seed($connection);
    app(AdoptionRegistry::class)->register('fixture', FakeAdoptionAdapter::class);
    $coordinator = static fn (): AdoptionCoordinator => new AdoptionCoordinator(app(AdoptionRegistry::class)->make('fixture', SystemActor::Background));
    $fingerprint = $coordinator()->plan()->fingerprint;

    FakeAdoptionAdapter::$failOnWrite = 2;
    expect(fn () => $coordinator()->apply($fingerprint, 1))->toThrow(RuntimeException::class, 'Injected adoption failure.');
    FakeAdoptionAdapter::reset();
    $run = (string) DB::table(AdoptionStore::Runs)->value('id');
    expect(DB::table(FakeAdoptionAdapter::Tables['items'][1])->pluck('id')->all())->toBe([1]);

    $directory = sys_get_temp_dir().'/nvl-adoption-race-'.bin2hex(random_bytes(8));
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Cannot create the adoption race barrier directory.');
    }
    $workers = [];

    try {
        foreach ([0, 1] as $index) {
            $worker = new Process([
                PHP_BINARY,
                dirname(__DIR__).'/Fixtures/adoption-race-worker.php',
                json_encode($connection->getConfig(), JSON_THROW_ON_ERROR),
                json_encode(['directory' => $directory, 'index' => $index, 'fingerprint' => $fingerprint, 'run' => $run], JSON_THROW_ON_ERROR),
            ], env: ['XDEBUG_MODE' => 'off'], timeout: 60);
            $worker->start();
            $workers[] = $worker;
        }
        $deadline = microtime(true) + 30;
        while (! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) {
            foreach ($workers as $worker) {
                $worker->checkTimeout();
                if (! $worker->isRunning()) {
                    throw new RuntimeException('An adoption race worker exited before release: '.$worker->getErrorOutput());
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The adoption race ready barrier timed out.');
            }
            usleep(10000);
            clearstatcache();
        }
        $identities = array_map(static fn (int $index): array => json_decode((string) file_get_contents($directory.'/ready-'.$index), true, flags: JSON_THROW_ON_ERROR), [0, 1]);
        $backend = (int) $connection->scalar('select pg_backend_pid()');
        expect(array_unique([$backend, ...array_column($identities, 'backend')]))->toHaveCount(3);
        [$holder, $waiter] = [(int) $identities[0]['backend'], (int) $identities[1]['backend']];

        file_put_contents($directory.'/release-0', 'release');
        $deadline = microtime(true) + 20;
        while (! is_file($directory.'/locked-0')) {
            if (! $workers[0]->isRunning() || microtime(true) >= $deadline) {
                throw new RuntimeException('The first adoption runner never paused inside its locked batch: '.$workers[0]->getErrorOutput());
            }
            usleep(10000);
            clearstatcache();
        }
        file_put_contents($directory.'/release-1', 'release');
        $blockers = [];
        $deadline = microtime(true) + 20;
        while (! in_array($holder, $blockers, true)) {
            if (! $workers[1]->isRunning() || microtime(true) >= $deadline) {
                throw new RuntimeException('The second adoption runner never waited on the run lock held by the first: '.$workers[1]->getErrorOutput());
            }
            usleep(10000);
            $blockers = array_map(intval(...), array_filter(explode(',', trim((string) $connection->scalar('select pg_blocking_pids(?)::text', [$waiter], false), '{}'))));
        }
        file_put_contents($directory.'/continue-0', 'continue');

        $outcomes = [];
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $outcomes[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        expect($blockers)->toContain($holder)
            ->and(array_sum(array_column($outcomes, 'written')))->toBe(5)
            ->and(array_sum(array_column($outcomes, 'writes')))->toBe(5)
            ->and(array_column($outcomes, 'state'))->toBe([AdoptionProgress::Applied, AdoptionProgress::Applied])
            ->and(DB::table(FakeAdoptionAdapter::Tables['items'][1])->orderBy('id')->pluck('id')->all())->toBe([1, 2, 3, 4])
            ->and(DB::table(FakeAdoptionAdapter::Tables['labels'][1])->orderBy('id')->pluck('id')->all())->toBe([1, 2])
            ->and(DB::table(AdoptionStore::Checkpoints)->orderBy('step')->pluck('processed', 'step')->map(static fn (mixed $count): int => (int) $count)->all())->toBe(['items' => 4, 'labels' => 2])
            ->and(DB::table(AdoptionStore::Runs)->value('status'))->toBe(AdoptionProgress::Applied)
            ->and($coordinator()->verify()->passed)->toBeTrue();
    } finally {
        foreach ($workers as $worker) {
            $worker->stop();
        }
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
        FakeAdoptionAdapter::reset();
    }
});
