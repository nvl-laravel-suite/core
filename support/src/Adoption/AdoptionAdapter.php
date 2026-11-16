<?php

declare(strict_types=1);

namespace Nvl\Support\Adoption;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Nvl\Support\Enums\SystemActor;

/**
 * Package hooks the shared coordinator drives. Build one instance per invocation; never keep it in a singleton.
 *
 * `plan()` must report every staged destination row that differs from its source as a collision, and
 * `assertCheckpoint()` must refuse a stored checkpoint whose committed output is no longer staged. On resume the
 * coordinator refuses a plan with collisions and a checkpoint that fails that check; a checkpoint never justifies
 * rewinding or overwriting a row.
 *
 * Subclasses that need services declare their own constructor and pass `actor` and `options` to this one; the
 * registry resolves them through the container with those two named parameters.
 *
 * @api
 *
 * @phpstan-import-type Cursor from AdoptionStep
 */
abstract class AdoptionAdapter
{
    /**
     * @param  array<string, mixed>  $options  Normalised options from the reviewed manifest
     */
    public function __construct(
        protected readonly Authenticatable|SystemActor $actor,
        protected readonly array $options = [],
    ) {}

    /**
     * Return the explicit actor this invocation runs as.
     */
    public function actor(): Authenticatable|SystemActor
    {
        return $this->actor;
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * Read the source graph and destination without writing, and return the reviewable plan. Its fingerprint must
     * cover the normalised options, and its collisions must include staged rows that differ from their source.
     */
    abstract public function plan(): AdoptionPlan;

    /**
     * Return the destination connection. Run and checkpoint rows are stored on it so they commit with each batch.
     */
    abstract public function connection(): Connection;

    /**
     * Authorize the actor for one phase through the package's normal authorization; throw to refuse.
     *
     * @param  'plan'|'apply'|'verify'|'activate'  $phase
     */
    abstract public function authorize(string $phase): void;

    /**
     * Run one batch transaction, including its commit. Hold an external lock here, for example a process lock, so it
     * is released even when COMMIT itself throws: Laravel runs no after-commit or after-rollback callbacks then.
     * Return the closure's result unchanged.
     */
    public function aroundBatch(Closure $batch): mixed
    {
        return $batch();
    }

    /**
     * Run verify's or activate's run-locked transaction, including its commit. Take any lock that `aroundBatch()` takes
     * here too, so every phase acquires it before the run row. Return the closure's result unchanged.
     */
    public function aroundVerification(Closure $operation): mixed
    {
        return $operation();
    }

    /**
     * Refuse a stored checkpoint whose committed output is not fully staged; throw instead of rewinding or overwriting.
     *
     * Called under the run lock, inside the batch transaction, the first time an apply invocation reads a step's stored
     * checkpoint, completed ones included. Re-read the source rows at or before `$cursor` (see `AdoptionStep::through()`),
     * map them as `writeBatch()` did and confirm every mapped destination row, child rows included, is still staged.
     * A null cursor means no source row was committed. The default fails closed, so custom adapters must implement it
     * before they can resume.
     *
     * @param  Cursor|null  $cursor
     * @param  int  $processed  Source rows the stored checkpoint counts
     */
    public function assertCheckpoint(AdoptionStep $step, ?array $cursor, int $processed): void
    {
        throw new AdoptionException('Adoption adapter ['.static::class."] cannot confirm the checkpoint of step [{$step->id}]; implement assertCheckpoint() before resuming.");
    }

    /**
     * Read and map at most `$limit` source rows after the keyset cursor. Called inside the batch transaction.
     *
     * @param  Cursor|null  $cursor
     */
    abstract public function nextBatch(AdoptionStep $step, ?array $cursor, int $limit): AdoptionBatch;

    /**
     * Write one batch inside the coordinator's transaction. Never overwrite a differing row; throw instead.
     *
     * Register cache invalidation or events with after-commit callbacks so they fire only once the checkpoint commits.
     *
     * @return string|null An opaque output revision stored with the checkpoint, or null
     */
    abstract public function writeBatch(AdoptionBatch $batch): ?string;

    /**
     * Scan the committed source and destination independently of checkpoints.
     */
    abstract public function verify(AdoptionPlan $plan): AdoptionVerification;
}
