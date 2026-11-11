<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Nvl\Support\Console\Commands\QueueQuarantineRetryCommand;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipQueueQuarantine;
use Nvl\Support\Tests\Fixtures\OwnershipQueueProbe;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;

it('quarantines ownership wire work on Core-only workers before terminal restoration', function (mixed $envelope): void {
    OwnershipQueueProbe::$restored = OwnershipQueueProbe::$handled = OwnershipQueueProbe::$failed = 0;
    config()->set('queue.failed', ['driver' => 'database-uuids', 'database' => DB::getDefaultConnection(), 'table' => 'failed_jobs']);
    app()->forgetInstance('queue.failer');
    DB::getSchemaBuilder()->create('jobs', static function (Blueprint $table): void {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    DB::getSchemaBuilder()->create('failed_jobs', static function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at');
    });
    $queue = Queue::connection('database');
    $id = $queue->push(new OwnershipQueueProbe);
    $raw = DB::table('jobs')->where('id', $id)->value('payload');
    $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    $payload['data']['nvl_ownership'] = $envelope;
    DB::table('jobs')->where('id', $id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'attempts' => 1]);
    expect(fn () => app('queue.worker')->process('database', $queue->pop(), new WorkerOptions(maxTries: 1)))->toThrow(MaxAttemptsExceededException::class);
    expect(OwnershipQueueProbe::$restored)->toBe(0)
        ->and(OwnershipQueueProbe::$handled)->toBe(0)
        ->and(OwnershipQueueProbe::$failed)->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('NVL queue envelope rejected:');
    $failure = DB::table('failed_jobs')->sole();
    expect(fn () => Artisan::call('queue:retry', ['id' => [$failure->uuid]]))->toThrow(OwnershipViolation::class, 'nvl:queue:retry')
        ->and(OwnershipQueueProbe::$restored)->toBe(0);
    app(OwnershipQueueQuarantine::class)->retry($failure->uuid);
    expect(DB::table('jobs')->value('payload'))->toBe($failure->payload)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(OwnershipQueueProbe::$restored)->toBe(0);
})->with(['malformed' => null, 'unsupported version' => [['version' => 99]], 'owned captured work' => [['version' => 1, 'mode' => 'owner', 'owner' => ['version' => 1, 'type' => 'company', 'storage' => 'source.companies', 'keyType' => 'int', 'key' => '7'], 'principal' => ['kind' => 'actor', 'type' => 'actor', 'id' => '1'], 'operation' => null, 'generations' => []]]]);

it('quarantines a missing-envelope command whose unavailable dependency prevents classification', function (): void {
    OwnershipQueueProbe::$restored = 0;
    config()->set('queue.failed', ['driver' => 'database-uuids', 'database' => DB::getDefaultConnection(), 'table' => 'failed_jobs']);
    app()->forgetInstance('queue.failer');
    DB::getSchemaBuilder()->create('jobs', static function (Blueprint $table): void {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    DB::getSchemaBuilder()->create('failed_jobs', static function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at');
    });
    $queue = Queue::connection('database');
    $id = $queue->push(new OwnershipQueueProbe);
    $wire = json_decode(DB::table('jobs')->where('id', $id)->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    $wire['data']['commandName'] = 'OwnershipUnavailableDependencyProbe';
    DB::table('jobs')->where('id', $id)->update(['payload' => json_encode($wire, JSON_THROW_ON_ERROR), 'attempts' => 1]);
    $loader = static function (string $class): void {
        if ($class === 'OwnershipUnavailableDependencyProbe') {
            throw new RuntimeException('An optional job dependency is unavailable.');
        }
    };
    spl_autoload_register($loader);
    try {
        try {
            app('queue.worker')->process('database', $queue->pop(), new WorkerOptions(maxTries: 1));
        } catch (RuntimeException) {
        }
        expect(OwnershipQueueProbe::$restored)->toBe(0)
            ->and(DB::table('failed_jobs')->value('exception'))->toContain('NVL queue envelope rejected:');
    } finally {
        spl_autoload_unregister($loader);
    }
});

/** Persist one ordinary host failure of probe work carrying the supplied wire metadata. */
function ownershipOrdinaryFailure(array $metadata): string
{
    OwnershipQueueProbe::$restored = OwnershipQueueProbe::$handled = OwnershipQueueProbe::$failed = 0;
    config()->set('queue.failed', ['driver' => 'database-uuids', 'database' => DB::getDefaultConnection(), 'table' => 'failed_jobs']);
    app()->forgetInstance('queue.failer');
    DB::getSchemaBuilder()->create('jobs', static function (Blueprint $table): void {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    DB::getSchemaBuilder()->create('failed_jobs', static function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at');
    });
    Queue::connection('database')->push(new OwnershipQueueProbe);
    $payload = json_decode(DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    $payload['data'] = [...$payload['data'], ...$metadata];
    DB::table('jobs')->delete();

    return app('queue.failer')->log('database', 'default', json_encode($payload, JSON_THROW_ON_ERROR), new RuntimeException('The mail transport is unavailable.'));
}

it('natively retries ordinary failed inert captured work when no enforcing runtime is enabled', function (bool $installed): void {
    if ($installed) {
        app()->instance('nvl.ownership.runtime', true);
        config()->set('nvl-ownership.enabled', false);
    }
    $id = ownershipOrdinaryFailure(['nvl_ownership' => ['version' => 1, 'mode' => 'disabled', 'owner' => null, 'principal' => null, 'operation' => null, 'generations' => []]]);

    expect(Artisan::call('queue:retry', ['id' => [$id]]))->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);
    app('queue.worker')->process('database', Queue::connection('database')->pop(), new WorkerOptions(maxTries: 1));
    expect(OwnershipQueueProbe::$handled)->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
})->with(['Core-only host' => false, 'runtime installed but disabled' => true]);

it('refuses native retry of ordinary failures whose capture a non-enforcing worker would reject', function (array $metadata, bool $enabled = false): void {
    $id = ownershipOrdinaryFailure($metadata);
    config()->set('nvl-ownership.enabled', $enabled);

    expect(fn () => Artisan::call('queue:retry', ['id' => [$id]]))->toThrow(OwnershipViolation::class, 'nvl:queue:retry')
        ->and(OwnershipQueueProbe::$restored)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
})->with([
    'owner authority' => [['nvl_ownership' => ['version' => 1, 'mode' => 'owner', 'owner' => ['version' => 1, 'type' => 'company', 'storage' => 'source.companies', 'keyType' => 'int', 'key' => '7'], 'principal' => ['kind' => 'actor', 'type' => 'actor', 'id' => '1'], 'operation' => null, 'generations' => []]]],
    'unowned runtime capture' => [['nvl_ownership' => ['version' => 2, 'mode' => 'unresolved', 'owner' => null, 'principal' => null, 'operation' => null, 'generations' => ['source' => 'generation']]]],
    'malformed envelope' => [['nvl_ownership' => null]],
    'retired metadata' => [['nvl_tenancy' => ['version' => 1, 'mode' => 'disabled', 'tenant_id' => null]]],
    'enabled configuration without its runtime' => [['nvl_ownership' => ['version' => 1, 'mode' => 'disabled', 'owner' => null, 'principal' => null, 'operation' => null, 'generations' => []]], true],
]);

it('raw retries captured and quarantined records with the Core command and names no other package on refusal', function (bool $installed, bool $quarantined): void {
    if ($installed) {
        app()->instance('nvl.ownership.runtime', true);
        config()->set('nvl-ownership.enabled', false);
    }
    $id = ownershipOrdinaryFailure(['nvl_ownership' => ['version' => 1, 'mode' => 'owner', 'owner' => ['version' => 1, 'type' => 'company', 'storage' => 'source.companies', 'keyType' => 'int', 'key' => '7'], 'principal' => ['kind' => 'actor', 'type' => 'actor', 'id' => '1'], 'operation' => null, 'generations' => []]]);
    if ($quarantined) {
        $payload = DB::table('failed_jobs')->value('payload');
        app('queue.failer')->forget($id);
        $id = app('queue.failer')->log('database', 'default', $payload, new OwnershipViolation(OwnershipQueueQuarantine::REJECTION_PREFIX.'Storage was unavailable.'));
    }
    $payload = DB::table('failed_jobs')->value('payload');

    try {
        Artisan::call('queue:retry', ['id' => [$id]]);
        $message = null;
    } catch (OwnershipViolation $exception) {
        $message = $exception->getMessage();
    }
    expect($message)->toBe('Quarantined NVL work requires nvl:queue:retry; native retry restores the captured command.')
        ->and(Artisan::call('nvl:queue:retry', ['id' => [$id]]))->toBe(0)
        ->and(Artisan::output())->toContain("Requeued raw NVL payload [{$id}].")
        ->and(DB::table('jobs')->value('payload'))->toBe($payload)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(OwnershipQueueProbe::$restored)->toBe(0);
})->with([
    'Core-only captured failure' => [false, false],
    'Core-only quarantined record' => [false, true],
    'disabled runtime quarantined record' => [true, true],
]);

it('refuses unidentified and malformed raw retry identifiers without changing failed-job storage', function (): void {
    $id = ownershipOrdinaryFailure([]);

    expect(Artisan::call('nvl:queue:retry', ['id' => [$id]]))->toBe(1)
        ->and(Artisan::output())->toContain('Raw retry requires an identified NVL quarantine record.')
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);

    $input = Mockery::mock(InputInterface::class);
    $input->shouldReceive('getArgument')->with('id')->andReturn([new stdClass]);
    $output = new BufferedOutput;
    $command = app(QueueQuarantineRetryCommand::class);
    $command->setLaravel(app());
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, $output));

    expect(app()->call([$command, 'handle']))->toBe(1)
        ->and($output->fetch())->toContain('Native failed-job IDs must be a nonempty list of strings.')
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});
