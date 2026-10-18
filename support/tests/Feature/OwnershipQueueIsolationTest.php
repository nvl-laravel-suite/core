<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Nvl\Support\Ownership\Services\OwnershipQueueQuarantine;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tests\Fixtures\OwnershipQueueProbe;

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
    expect(fn () => Artisan::call('queue:retry', ['id' => [$failure->uuid]]))->toThrow(TenantBoundaryViolation::class, 'nvl:queue:retry')
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
