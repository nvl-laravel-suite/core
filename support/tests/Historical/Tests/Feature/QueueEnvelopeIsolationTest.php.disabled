<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Console\QueueQuarantineRetryCommand;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueueHandler;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipQueueQuarantine;
use Nvl\Support\Ownership\Services\PersistedOwnershipStorage;

/** Records native command restoration and both execution entry points. */
final class QueueIsolationProbeJob implements ShouldQueue
{
    public static int $restored = 0;

    public static int $handled = 0;

    public static int $failed = 0;

    public int $tries = 1;

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        self::$restored++;
    }

    /** Execute ordinary host work. */
    public function handle(): void
    {
        self::$handled++;
    }

    /** Observe an unsafe native failure callback. */
    public function failed(?Throwable $exception): void
    {
        self::$failed++;
    }
}

/** Models an explicitly enforcing host handler with a separate unguarded method. */
final class QueueIsolationGuardedHostHandler extends CallQueuedHandler implements OwnershipQueueHandler
{
    /** @param array<string, mixed> $data */
    public function validate(array $data): void
    {
        throw new OwnershipViolation('Fixture tenant payload admission denied.');
    }

    /** @param array<string, mixed> $data */
    public function call(Job $job, array $data): void
    {
        throw new OwnershipViolation('Fixture tenant execution admission denied.');
    }

    /** @param array<string, mixed> $data */
    public function failed(array $data, mixed $e, string $uuid, ?Job $job = null): void
    {
        throw new OwnershipViolation('Fixture tenant failure admission denied.');
    }

    /** @param array<string, mixed> $data */
    public function unguarded(Job $job, array $data): void
    {
        parent::call($job, $data);
    }
}

beforeEach(function (): void {
    QueueIsolationProbeJob::$restored = 0;
    QueueIsolationProbeJob::$handled = 0;
    QueueIsolationProbeJob::$failed = 0;
    config()->set('queue.default', 'sync');
    config()->set('queue.failed', ['driver' => 'database-uuids', 'database' => DB::getDefaultConnection(), 'table' => 'failed_jobs']);
    app()->forgetInstance('queue.failer');
    Schema::create('failed_jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at');
    });
    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

afterEach(function (): void {
    Queue::createPayloadUsing(null);
});

it('rejects malformed native quarantine retry identifiers before contacting failed storage', function (mixed $id): void {
    app(Kernel::class)->registerCommand(new QueueQuarantineRetryCommand);
    $result = Artisan::call('nvl:queue:retry', ['id' => [$id]]);

    expect($result)->toBe(1)
        ->and(Artisan::output())->toContain('Native failed-job IDs must be non-empty strings.')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
})->with(['null' => null, 'integer' => 42, 'empty' => '']);

it('runs unrelated jobs before resolving package context or storage', function (bool $enabled): void {
    if ($enabled) {
        app()->instance('nvl.tenancy.runtime', true);
        config()->set('nvl-tenancy.enabled', true);
    }
    foreach ([OwnershipContext::class, PersistedOwnershipStorage::class] as $service) {
        app()->beforeResolving($service, static function (): void {
            throw new LogicException('Unrelated queue work resolved package state.');
        });
    }
    Queue::push(new QueueIsolationProbeJob);
    expect(QueueIsolationProbeJob::$handled)->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
})->with([false, true]);

it('quarantines captured work before a terminal native worker can restore its command', function (mixed $metadata): void {
    $queue = Queue::connection('database');
    $queue->push(new QueueIsolationProbeJob);
    $record = DB::table('jobs')->first();
    $payload = json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR);
    $payload['data']['nvl_tenancy'] = $metadata;
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    DB::table('jobs')->where('id', $record->id)->update(['payload' => $raw, 'attempts' => 1]);

    expect(fn () => app('queue.worker')->process('database', $queue->pop(), new WorkerOptions(maxTries: 1)))
        ->toThrow(MaxAttemptsExceededException::class);

    $failure = DB::table('failed_jobs')->first();
    expect(QueueIsolationProbeJob::$restored)->toBe(0)
        ->and(QueueIsolationProbeJob::$handled)->toBe(0)
        ->and(QueueIsolationProbeJob::$failed)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($failure->payload)->toBe($raw)
        ->and($failure->exception)->toContain('OwnershipViolation');

    expect(fn () => Artisan::call('queue:retry', ['id' => [$failure->uuid]]))
        ->toThrow(OwnershipViolation::class, 'nvl:queue:retry');
    expect(QueueIsolationProbeJob::$restored)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);

    app(OwnershipQueueQuarantine::class)->retry($failure->uuid);
    expect(DB::table('jobs')->value('payload'))->toBe($raw)
        ->and(QueueIsolationProbeJob::$restored)->toBe(0);
    app('queue.worker')->process('database', $queue->pop(), new WorkerOptions(maxTries: 1));
    expect(QueueIsolationProbeJob::$restored)->toBe(0)
        ->and(QueueIsolationProbeJob::$failed)->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
})->with([
    'tenant' => [['version' => 1, 'mode' => 'tenant', 'tenant_id' => '01954c28-56ec-7bda-9721-e5c05d7e1a54']],
    'unsupported' => [['version' => 99, 'mode' => 'disabled', 'tenant_id' => null]],
    'malformed' => [null],
]);

it('rejects sync dispatch without invoking restoration or native failure callbacks', function (): void {
    Queue::createPayloadUsing(static fn (): array => ['data' => ['commandName' => QueueIsolationProbeJob::class, 'command' => serialize(new QueueIsolationProbeJob), 'nvl_tenancy' => ['version' => 1, 'mode' => 'tenant', 'tenant_id' => '01954c28-56ec-7bda-9721-e5c05d7e1a54']]]);
    expect(fn () => Queue::push(new QueueIsolationProbeJob))->toThrow(OwnershipViolation::class)
        ->and(QueueIsolationProbeJob::$restored)->toBe(0)
        ->and(QueueIsolationProbeJob::$handled)->toBe(0)
        ->and(QueueIsolationProbeJob::$failed)->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
    $id = DB::table('failed_jobs')->value('uuid');
    expect(fn () => app(OwnershipQueueQuarantine::class)->retry($id))->toThrow(OwnershipViolation::class)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(QueueIsolationProbeJob::$restored)->toBe(0);
});

it('preserves native retry behavior for unrelated failed host work', function (): void {
    $queue = Queue::connection('database');
    $queue->push(new QueueIsolationProbeJob);
    $record = DB::table('jobs')->first();
    DB::table('jobs')->delete();
    $id = app('queue.failer')->log('database', 'default', $record->payload, new RuntimeException('Host failure.'));

    expect(fn () => app(OwnershipQueueQuarantine::class)->retry($id))->toThrow(OwnershipViolation::class)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(QueueIsolationProbeJob::$restored)->toBe(0);

    Artisan::call('queue:retry', ['id' => [$id]]);
    expect(QueueIsolationProbeJob::$restored)->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
    app('queue.worker')->process('database', $queue->pop(), new WorkerOptions(maxTries: 1));
    expect(QueueIsolationProbeJob::$handled)->toBe(1);
});

it('rejects identified quarantine records before native restoration for every retry selector', function (string $selector): void {
    $queue = Queue::connection('database');
    $queue->push(new QueueIsolationProbeJob);
    $record = DB::table('jobs')->first();
    DB::table('jobs')->delete();
    $id = app('queue.failer')->log('database', 'default', $record->payload, new OwnershipViolation(OwnershipQueueQuarantine::REJECTION_PREFIX.'Captured tenant work requires the enforcing runtime.'));
    $arguments = match ($selector) {
        'id' => ['id' => [$id]],
        'all' => ['id' => ['all']],
        'queue' => ['--queue' => 'default'],
        'range' => ['--range' => [DB::table('failed_jobs')->value('id').'-'.DB::table('failed_jobs')->value('id')]],
    };
    if ($selector === 'range') {
        config()->set('queue.failed.driver', 'database');
        app()->forgetInstance('queue.failer');
    }

    expect(fn () => Artisan::call('queue:retry', $arguments))
        ->toThrow(OwnershipViolation::class, 'nvl:queue:retry');
    expect(QueueIsolationProbeJob::$restored)->toBe(0)
        ->and(QueueIsolationProbeJob::$handled)->toBe(0)
        ->and(QueueIsolationProbeJob::$failed)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->value('payload'))->toBe($record->payload);
})->with(['id', 'all', 'queue', 'range']);

it('quarantines captured work on enabled workers retaining an incompatible host handler', function (bool $terminal): void {
    $queue = Queue::connection('database');
    $queue->push(new QueueIsolationProbeJob);
    $record = DB::table('jobs')->first();
    $payload = json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR);
    $payload['data']['nvl_tenancy'] = ['version' => 1, 'mode' => 'tenant', 'tenant_id' => '01954c28-56ec-7bda-9721-e5c05d7e1a54'];
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    DB::table('jobs')->where('id', $record->id)->update(['payload' => $raw, 'attempts' => $terminal ? 1 : 0]);
    $handler = new CallQueuedHandler(app(BusDispatcher::class), app());
    app()->instance(CallQueuedHandler::class, $handler);
    app()->instance('nvl.tenancy.runtime', true);
    config()->set('nvl-tenancy.enabled', true);

    if ($terminal) {
        expect(fn () => app('queue.worker')->process('database', $queue->pop(), new WorkerOptions(maxTries: 1)))
            ->toThrow(MaxAttemptsExceededException::class);
    } else {
        app('queue.worker')->process('database', $queue->pop(), new WorkerOptions(maxTries: 1));
    }

    expect(app(CallQueuedHandler::class))->toBe($handler)
        ->and(QueueIsolationProbeJob::$restored)->toBe(0)
        ->and(QueueIsolationProbeJob::$handled)->toBe(0)
        ->and(QueueIsolationProbeJob::$failed)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->value('payload'))->toBe($raw)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('Queued ownership requires its native scalar envelope.');
})->with([false, true]);

it('quarantines enabled sync work retaining an incompatible host handler before restoration', function (): void {
    $handler = new CallQueuedHandler(app(BusDispatcher::class), app());
    app()->instance(CallQueuedHandler::class, $handler);
    app()->instance('nvl.tenancy.runtime', true);
    config()->set('nvl-tenancy.enabled', true);
    Queue::createPayloadUsing(static fn (): array => ['data' => ['commandName' => QueueIsolationProbeJob::class, 'command' => serialize(new QueueIsolationProbeJob), 'nvl_tenancy' => ['version' => 1, 'mode' => 'tenant', 'tenant_id' => '01954c28-56ec-7bda-9721-e5c05d7e1a54']]]);

    expect(fn () => Queue::push(new QueueIsolationProbeJob))->toThrow(OwnershipViolation::class)
        ->and(app(CallQueuedHandler::class))->toBe($handler)
        ->and(QueueIsolationProbeJob::$restored)->toBe(0)
        ->and(QueueIsolationProbeJob::$handled)->toBe(0)
        ->and(QueueIsolationProbeJob::$failed)->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('Queued ownership requires its native scalar envelope.');
});

it('rejects retired wire metadata before delegating to a host handler', function (mixed $metadata, bool $runtime = true): void {
    $queue = Queue::connection('database');
    $queue->push(new QueueIsolationProbeJob);
    $record = DB::table('jobs')->first();
    $payload = json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR);
    $payload['data']['nvl_tenancy'] = $metadata;
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    DB::table('jobs')->where('id', $record->id)->update(['payload' => $raw]);
    app()->instance(CallQueuedHandler::class, new QueueIsolationGuardedHostHandler(app(BusDispatcher::class), app()));
    if ($runtime) {
        app()->instance('nvl.tenancy.runtime', true);
    }
    config()->set('nvl-tenancy.enabled', true);

    app('queue.worker')->process('database', $queue->pop(), new WorkerOptions(maxTries: 1));

    expect(DB::table('failed_jobs')->value('payload'))->toBe($raw)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain(OwnershipQueueQuarantine::REJECTION_PREFIX)
        ->and(QueueIsolationProbeJob::$restored)->toBe(0)
        ->and(QueueIsolationProbeJob::$handled)->toBe(0)
        ->and(QueueIsolationProbeJob::$failed)->toBe(0);
    expect(fn () => Artisan::call('queue:retry', ['id' => [DB::table('failed_jobs')->value('uuid')]]))
        ->toThrow(OwnershipViolation::class, 'nvl:queue:retry');
    expect(QueueIsolationProbeJob::$restored)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
})->with([
    'malformed' => [null],
    'unsupported' => [['version' => 99, 'mode' => 'tenant', 'tenant_id' => '01954c28-56ec-7bda-9721-e5c05d7e1a54']],
    'disabled on enabled worker' => [['version' => 1, 'mode' => 'disabled', 'tenant_id' => null]],
    'enabled without runtime' => [['version' => 1, 'mode' => 'disabled', 'tenant_id' => null], false],
]);

it('rejects retired wire authority before any host handler or terminal callback', function (string $mode): void {
    $metadata = ['version' => 1, 'mode' => 'tenant', 'tenant_id' => '01954c28-56ec-7bda-9721-e5c05d7e1a54'];
    $queue = Queue::connection('database');
    $queue->push(new QueueIsolationProbeJob);
    $record = DB::table('jobs')->first();
    $payload = json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR);
    $payload['data']['nvl_tenancy'] = $metadata;
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    DB::table('jobs')->where('id', $record->id)->update(['payload' => $raw, 'attempts' => $mode === 'terminal' ? 1 : 0]);
    app()->instance(CallQueuedHandler::class, new QueueIsolationGuardedHostHandler(app(BusDispatcher::class), app()));
    app()->instance('nvl.tenancy.runtime', true);
    config()->set('nvl-tenancy.enabled', true);

    if ($mode === 'sync') {
        DB::table('jobs')->delete();
        Queue::createPayloadUsing(static fn (): array => ['data' => [
            'commandName' => QueueIsolationProbeJob::class,
            'command' => serialize(new QueueIsolationProbeJob),
            'nvl_tenancy' => $metadata,
        ]]);
        expect(fn () => Queue::push(new QueueIsolationProbeJob))
            ->toThrow(OwnershipViolation::class, OwnershipQueueQuarantine::REJECTION_PREFIX);
    } elseif ($mode === 'terminal') {
        expect(fn () => app('queue.worker')->process('database', $queue->pop(), new WorkerOptions(maxTries: 1)))
            ->toThrow(MaxAttemptsExceededException::class);
    } else {
        app('queue.worker')->process('database', $queue->pop(), new WorkerOptions(maxTries: 1));
    }

    expect(QueueIsolationProbeJob::$restored)->toBe(0)
        ->and(QueueIsolationProbeJob::$handled)->toBe(0)
        ->and(QueueIsolationProbeJob::$failed)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('Queued ownership requires its native scalar envelope.');
})->with(['worker', 'terminal', 'sync']);

it('rejects retired wire authority regardless of the queued handler and method', function (string $handlerName): void {
    $queue = Queue::connection('database');
    $queue->push(new QueueIsolationProbeJob);
    $record = DB::table('jobs')->first();
    $payload = json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR);
    $payload['job'] = $handlerName;
    $payload['data']['nvl_tenancy'] = ['version' => 1, 'mode' => 'tenant', 'tenant_id' => '01954c28-56ec-7bda-9721-e5c05d7e1a54'];
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    DB::table('jobs')->where('id', $record->id)->update(['payload' => $raw]);
    $default = new QueueIsolationGuardedHostHandler(app(BusDispatcher::class), app());
    app()->instance(CallQueuedHandler::class, $default);
    app()->instance('host.queue.guard', $default);
    app()->instance('host.queue.raw', new CallQueuedHandler(app(BusDispatcher::class), app()));
    app()->instance('nvl.tenancy.runtime', true);
    config()->set('nvl-tenancy.enabled', true);

    app('queue.worker')->process('database', $queue->pop(), new WorkerOptions(maxTries: 1));

    expect(app(CallQueuedHandler::class))->toBe($default)
        ->and(QueueIsolationProbeJob::$restored)->toBe(0)
        ->and(QueueIsolationProbeJob::$handled)->toBe(0)
        ->and(QueueIsolationProbeJob::$failed)->toBe(0)
        ->and(DB::table('failed_jobs')->value('payload'))->toBe($raw)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('Queued ownership requires its native scalar envelope.');
})->with(['host.queue.raw@call', 'host.queue.guard@unguarded']);

it('never restores rejected work when native failed-job persistence is unavailable', function (bool $disabled): void {
    if ($disabled) {
        app()->instance('queue.failer', new NullFailedJobProvider);
    } else {
        Schema::drop('failed_jobs');
    }
    $queue = Queue::connection('database');
    $queue->push(new QueueIsolationProbeJob);
    $record = DB::table('jobs')->first();
    $payload = json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR);
    $payload['data']['nvl_tenancy'] = null;
    DB::table('jobs')->where('id', $record->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);

    expect(fn () => app('queue.worker')->process('database', $queue->pop(), new WorkerOptions(maxTries: 1)))
        ->toThrow($disabled ? OwnershipConfigurationInvalid::class : QueryException::class);
    expect(QueueIsolationProbeJob::$restored)->toBe(0)
        ->and(QueueIsolationProbeJob::$handled)->toBe(0)
        ->and(QueueIsolationProbeJob::$failed)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with([true, false]);

it('rejects every retired envelope even when storage has no ownership marker', function (bool $adopted): void {
    if ($adopted) {
        Schema::create('nvl_ownership_installation_state', function (Blueprint $table): void {
            $table->string('resource');
            $table->string('physical_table');
        });
        DB::table('nvl_ownership_installation_state')->insert(['resource' => 'fixture.records', 'physical_table' => 'disabled_boundary_records']);
    }
    Queue::createPayloadUsing(static fn (): array => ['data' => ['commandName' => QueueIsolationProbeJob::class, 'command' => serialize(new QueueIsolationProbeJob), 'nvl_tenancy' => ['version' => 1, 'mode' => 'disabled', 'tenant_id' => null]]]);

    expect(fn () => Queue::push(new QueueIsolationProbeJob))->toThrow(OwnershipViolation::class)
        ->and(QueueIsolationProbeJob::$restored)->toBe(0)
        ->and(QueueIsolationProbeJob::$handled)->toBe(0)
        ->and(QueueIsolationProbeJob::$failed)->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
})->with([true, false]);
