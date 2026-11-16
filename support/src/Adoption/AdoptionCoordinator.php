<?php

declare(strict_types=1);

namespace Nvl\Support\Adoption;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Nvl\Support\Enums\SystemActor;
use Throwable;

/**
 * Drives one adapter through plan, checkpointed apply, verify and activate on its destination connection.
 *
 * Each batch commits its rows and checkpoint together under a lock on the run row. Source and destination writers
 * must stay stopped from the final apply until activation.
 *
 * @api
 *
 * @phpstan-import-type Run from AdoptionStore
 * @phpstan-import-type Checkpoint from AdoptionStore
 */
final readonly class AdoptionCoordinator implements Adoption
{
    private Connection $connection;

    private AdoptionStore $store;

    public function __construct(private AdoptionAdapter $adapter)
    {
        $this->connection = $adapter->connection();
        $this->store = new AdoptionStore($this->connection);
    }

    /**
     * Authorize and return the adapter's plan. Writes nothing.
     */
    public function plan(): AdoptionPlan
    {
        $this->adapter->authorize('plan');

        return $this->adapter->plan();
    }

    /**
     * Return the stored progress of the run for this plan's scope, or null when there is none. Writes nothing.
     */
    public function progress(AdoptionPlan $plan): ?AdoptionProgress
    {
        if (! $this->store->installed()) {
            return null;
        }
        $run = $this->store->findByScope($this->scopeHash($plan));

        return $run === null ? null : $this->snapshot($run, $plan);
    }

    public function apply(string $fingerprint, int $batchSize = 100, ?string $run = null): AdoptionProgress
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Adoption batch size must be a positive integer.');
        }
        $this->adapter->authorize('apply');
        $plan = $this->reviewedPlan($fingerprint);
        $existing = $this->store->findByScope($this->scopeHash($plan));
        if ($existing !== null) {
            $this->assertSameRun($existing, $plan);
            if ($run === null) {
                throw new AdoptionException("Adoption run [{$existing['id']}] already exists for this scope; pass --run={$existing['id']} to resume it.");
            }
            if ($run !== $existing['id']) {
                throw new AdoptionException("Adoption run [{$run}] does not belong to this adoption scope.");
            }
            if ($existing['status'] === AdoptionProgress::Active) {
                throw new AdoptionException("Adoption run [{$existing['id']}] is already active.");
            }
        } elseif ($run !== null) {
            throw new AdoptionException("Adoption run [{$run}] does not exist for this scope.");
        }
        if ($plan->collisions !== []) {
            $committed = $existing !== null && array_filter($this->store->checkpoints($existing['id']), static fn (array $checkpoint): bool => $checkpoint['processed'] > 0) !== [];
            throw new AdoptionException(($committed ? 'Adoption drift behind the cursor: ' : 'Adoption collision: ').$plan->collisions[0]);
        }
        $runId = $existing['id'] ?? $this->store->create($this->scopeHash($plan), $plan, $this->actorIdentity())
            ?? throw new AdoptionException('A concurrent adoption run was created for this scope; resume it with --run.');

        $written = 0;
        try {
            foreach ($plan->steps as $step) {
                while ($this->adapter->aroundBatch(fn (): bool => $this->connection->transaction(fn (): bool => $this->applyBatch($runId, $plan, $step, $batchSize))) === true) {
                    $written++;
                }
            }
            $this->connection->transaction(function () use ($runId, $plan): void {
                $locked = $this->store->lock($runId);
                $this->assertRunMatches($locked, $plan);
                if (in_array($locked['status'], [AdoptionProgress::Planned, AdoptionProgress::Applying, AdoptionProgress::Failed], true)) {
                    $this->store->update($runId, ['status' => AdoptionProgress::Applied]);
                }
            });
        } catch (Throwable $exception) {
            $this->markFailed($runId);

            throw $exception;
        }

        return $this->snapshot($this->store->find($runId) ?? throw new AdoptionException("Adoption run [{$runId}] disappeared."), $plan, $written);
    }

    public function verify(): AdoptionVerification
    {
        $this->adapter->authorize('verify');
        $this->assertOutsideTransaction();
        $this->store->assertInstalled();
        $plan = $this->adapter->plan();
        $this->assertProtocol($plan);
        $run = $this->existingRun($plan);

        return $this->connection->transaction(function () use ($run, $plan): AdoptionVerification {
            $locked = $this->store->lock($run['id']);
            $this->assertRunMatches($locked, $plan);
            $verification = $this->scan($locked['id'], $plan);
            if ($locked['status'] === AdoptionProgress::Active) {
                return $verification;
            }
            if ($verification->passed) {
                $this->store->update($locked['id'], ['status' => AdoptionProgress::Verified, 'verification_digest' => $verification->digest, 'verified_at' => Date::now()]);
            } elseif ($locked['status'] === AdoptionProgress::Verified) {
                $this->store->update($locked['id'], ['status' => AdoptionProgress::Applied, 'verification_digest' => null, 'verified_at' => null]);
            }

            return $verification;
        });
    }

    public function activate(string $fingerprint): AdoptionProgress
    {
        $this->adapter->authorize('activate');
        $plan = $this->reviewedPlan($fingerprint);
        $run = $this->existingRun($plan);
        $this->connection->transaction(function () use ($run, $plan): void {
            $locked = $this->store->lock($run['id']);
            $this->adapter->authorize('activate');
            $this->assertRunMatches($locked, $plan);
            if ($locked['status'] === AdoptionProgress::Active) {
                return;
            }
            if ($locked['status'] !== AdoptionProgress::Verified || $locked['verified_at'] === null || $locked['verification_digest'] === null) {
                throw new AdoptionException("Adoption run [{$locked['id']}] requires a passed verification before activation.");
            }
            $verification = $this->scan($locked['id'], $plan);
            if (! $verification->passed || ! hash_equals($locked['verification_digest'], $verification->digest)) {
                throw new AdoptionException("Adoption run [{$locked['id']}] changed since verification; verify it again before activation.");
            }
            $this->store->update($locked['id'], ['status' => AdoptionProgress::Active, 'activated_at' => Date::now()]);
        });

        return $this->snapshot($this->store->find($run['id']) ?? throw new AdoptionException("Adoption run [{$run['id']}] disappeared."), $plan);
    }

    /**
     * Lock the run, re-check it, then read, write and checkpoint one batch. Return false when the step is complete.
     */
    private function applyBatch(string $runId, AdoptionPlan $plan, AdoptionStep $step, int $limit): bool
    {
        $run = $this->store->lock($runId);
        $this->assertRunMatches($run, $plan);
        $checkpoint = $this->store->checkpoints($runId)[$step->id] ?? null;
        if ($checkpoint !== null && $checkpoint['complete']) {
            return false;
        }
        if (! in_array($run['status'], [AdoptionProgress::Planned, AdoptionProgress::Applying, AdoptionProgress::Failed], true)) {
            throw new AdoptionException("Adoption run [{$runId}] is {$run['status']} and accepts no further batches.");
        }
        $cursor = $checkpoint['cursor'] ?? null;
        $batch = $this->adapter->nextBatch($step, $cursor, $limit);
        $this->assertBatch($step, $batch, $cursor, $limit);
        $revision = $this->adapter->writeBatch($batch);
        $this->store->saveCheckpoint($runId, [
            'step' => $step->id,
            'cursor' => $batch->cursorOut,
            'complete' => $batch->complete,
            'processed' => ($checkpoint['processed'] ?? 0) + count($batch->rows),
            'batch_digest' => hash('sha256', ($checkpoint['batch_digest'] ?? '').$batch->digest),
            'output_revision' => $revision ?? $checkpoint['output_revision'] ?? null,
        ]);
        $this->store->update($runId, ['status' => AdoptionProgress::Applying, 'verification_digest' => null, 'verified_at' => null]);

        return true;
    }

    /**
     * @param  array<string, int|string>|null  $cursor
     */
    private function assertBatch(AdoptionStep $step, AdoptionBatch $batch, ?array $cursor, int $limit): void
    {
        if ($batch->step !== $step->id || $batch->cursorIn !== $cursor) {
            throw new AdoptionException("Adoption step [{$step->id}] returned a batch for a different step or cursor.");
        }
        if (count($batch->rows) > $limit) {
            throw new AdoptionException("Adoption step [{$step->id}] returned more rows than the batch limit.");
        }
        if ($batch->cursorOut !== null) {
            $step->assertCursor($batch->cursorOut);
        }
        if (! $batch->complete && ($batch->rows === [] || $batch->cursorOut === null || $batch->cursorOut === $cursor)) {
            throw new AdoptionException("Adoption step [{$step->id}] returned an incomplete batch that does not advance its cursor.");
        }
    }

    /**
     * Combine the adapter's independent scan with the run's checkpoint completeness and counts.
     */
    private function scan(string $runId, AdoptionPlan $plan): AdoptionVerification
    {
        $checkpoints = $this->store->checkpoints($runId);
        $problems = [];
        foreach ($plan->steps as $step) {
            $checkpoint = $checkpoints[$step->id] ?? null;
            if ($checkpoint === null || ! $checkpoint['complete']) {
                $problems[] = "Adoption step [{$step->id}] has not completed.";
            } elseif (isset($plan->expected[$step->id]) && $plan->expected[$step->id] !== $checkpoint['processed']) {
                $problems[] = "Adoption step [{$step->id}] processed {$checkpoint['processed']} of {$plan->expected[$step->id]} expected rows.";
            }
        }

        return $this->adapter->verify($plan)->withProblems($problems);
    }

    /**
     * Refuse an open transaction, a protocol mismatch or a changed fingerprint before any write.
     */
    private function reviewedPlan(string $fingerprint): AdoptionPlan
    {
        $this->assertOutsideTransaction();
        $this->store->assertInstalled();
        $plan = $this->adapter->plan();
        $this->assertProtocol($plan);
        if (! hash_equals($plan->fingerprint, $fingerprint)) {
            throw new AdoptionException("The adoption fingerprint for [{$plan->package}] changed since review; plan again and review the new fingerprint.");
        }

        return $plan;
    }

    /**
     * @return Run
     */
    private function existingRun(AdoptionPlan $plan): array
    {
        $run = $this->store->findByScope($this->scopeHash($plan))
            ?? throw new AdoptionException("No adoption run exists for [{$plan->package}] in this scope; apply it first.");
        $this->assertSameRun($run, $plan);

        return $run;
    }

    /**
     * @param  Run  $run
     */
    private function assertSameRun(array $run, AdoptionPlan $plan): void
    {
        if (! hash_equals($run['fingerprint'], $plan->fingerprint)) {
            throw new AdoptionException("Adoption run [{$run['id']}] was started with a different fingerprint; a competing plan cannot share its scope.");
        }
        if ($run['options_digest'] === null || ! hash_equals($run['options_digest'], $plan->optionsDigest)) {
            throw new AdoptionException("Adoption run [{$run['id']}] was started with different options; resume it with the reviewed manifest.");
        }
        if ($run['adapter_version'] !== $plan->adapterVersion) {
            throw new AdoptionException("Adoption run [{$run['id']}] was written by unknown adapter version [{$run['adapter_version']}]; this adapter is [{$plan->adapterVersion}].");
        }
    }

    /**
     * @param  Run  $run
     */
    private function assertRunMatches(array $run, AdoptionPlan $plan): void
    {
        $this->assertSameRun($run, $plan);
        if ($run['scope_hash'] !== $this->scopeHash($plan)) {
            throw new AdoptionException("Adoption run [{$run['id']}] belongs to a different scope.");
        }
    }

    private function assertProtocol(AdoptionPlan $plan): void
    {
        if ($plan->protocol !== AdoptionPlan::Protocol) {
            throw new AdoptionException("Adoption plan protocol [{$plan->protocol}] is not supported; expected [".AdoptionPlan::Protocol.'].');
        }
    }

    private function assertOutsideTransaction(): void
    {
        if ($this->connection->transactionLevel() !== 0) {
            throw new AdoptionException("Adoption requires connection [{$this->connection->getName()}] outside an open transaction.");
        }
    }

    /**
     * Hash the destination connection, package and source and destination identities; never the fingerprint.
     */
    private function scopeHash(AdoptionPlan $plan): string
    {
        return AdoptionPlan::digest([
            'connection' => $this->connection->getName(),
            'database' => $this->connection->getDatabaseName(),
            'prefix' => $this->connection->getTablePrefix(),
            'package' => $plan->package,
            'source' => $plan->source,
            'destination' => $plan->destination,
        ]);
    }

    /**
     * @return array{type: string, id: string}
     */
    private function actorIdentity(): array
    {
        $actor = $this->adapter->actor();
        if ($actor instanceof SystemActor) {
            return ['type' => SystemActor::class, 'id' => $actor->name];
        }
        $identifier = $actor->getAuthIdentifier();
        if ((! is_int($identifier) && ! is_string($identifier)) || $identifier === '') {
            throw new AdoptionException('Adoption actors require a scalar identity.');
        }

        return ['type' => $actor instanceof Model ? $actor->getMorphClass() : $actor::class, 'id' => (string) $identifier];
    }

    /**
     * Record a failed run after its batch transaction rolled back; never hide the original failure.
     */
    private function markFailed(string $runId): void
    {
        if ($this->connection->transactionLevel() !== 0) {
            return;
        }
        try {
            $this->connection->table(AdoptionStore::Runs)->where('id', $runId)
                ->whereIn('status', [AdoptionProgress::Planned, AdoptionProgress::Applying])
                ->update(['status' => AdoptionProgress::Failed, 'updated_at' => Date::now()]);
        } catch (Throwable) {
            // The original failure is rethrown by the caller.
        }
    }

    /**
     * @param  Run  $run
     */
    private function snapshot(array $run, AdoptionPlan $plan, int $written = 0): AdoptionProgress
    {
        $checkpoints = $this->store->checkpoints($run['id']);
        $steps = [];
        foreach ($plan->steps as $step) {
            $checkpoint = $checkpoints[$step->id] ?? null;
            $steps[$step->id] = [
                'cursor' => $checkpoint['cursor'] ?? null,
                'processed' => $checkpoint['processed'] ?? 0,
                'expected' => $plan->expected[$step->id] ?? null,
                'complete' => $checkpoint['complete'] ?? false,
            ];
        }

        return new AdoptionProgress($run['id'], $run['package'], $run['fingerprint'], $run['status'], $steps, $run['verified_at'], $run['activated_at'], $written);
    }
}
