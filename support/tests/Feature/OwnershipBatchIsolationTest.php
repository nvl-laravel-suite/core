<?php

declare(strict_types=1);

use Illuminate\Bus\BatchRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;
use Nvl\Support\Tests\Fixtures\OwnershipQueueProbe;

it('keeps owned native batch callbacks inert when the ownership runtime is absent', function (bool $owned): void {
    config()->set('queue.batching.database', DB::getDefaultConnection());
    OwnershipQueueProbe::$restored = 0;
    DB::getSchemaBuilder()->create('job_batches', static function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });
    $options = ['then' => [new OwnershipQueueProbe]];
    if ($owned) {
        $options['nvl_ownership'] = OwnershipJobEnvelope::fromSnapshot(new OwnershipSnapshot(OwnershipContextMode::Owner, new OwnerReference('company', 'store.companies', 'int', '7'), ['kind' => 'actor', 'type' => 'actor', 'id' => '1']))->toArray();
    }
    DB::table('job_batches')->insert(['id' => 'batch', 'name' => 'native', 'total_jobs' => 1, 'pending_jobs' => 1, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'options' => serialize($options), 'created_at' => time()]);
    $repository = app(BatchRepository::class);
    if ($owned) {
        expect(fn () => $repository->find('batch'))->toThrow(OwnershipViolation::class)
            ->and(OwnershipQueueProbe::$restored)->toBe(0);
    } else {
        expect($repository->find('batch')->id)->toBe('batch')
            ->and(OwnershipQueueProbe::$restored)->toBe(1);
    }
})->with([true, false]);
