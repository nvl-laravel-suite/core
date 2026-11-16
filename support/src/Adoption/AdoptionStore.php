<?php

declare(strict_types=1);

namespace Nvl\Support\Adoption;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use JsonException;
use stdClass;

/**
 * Reads and writes adoption runs and checkpoints on one destination connection. Stores hashes, cursors and counts only.
 *
 * @api
 *
 * @phpstan-import-type Cursor from AdoptionStep
 *
 * @phpstan-type Run array{id: string, scope_hash: string, package: string, fingerprint: string, adapter_version: string, status: string, verification_digest: ?string, verified_at: ?string, activated_at: ?string, options_digest: ?string}
 * @phpstan-type Checkpoint array{step: string, cursor: Cursor|null, complete: bool, processed: int, batch_digest: string, output_revision: ?string}
 */
final readonly class AdoptionStore
{
    public const string Runs = 'nvl_core_adoption_runs';

    public const string Checkpoints = 'nvl_core_adoption_checkpoints';

    public function __construct(private Connection $connection) {}

    /**
     * Whether both adoption tables exist on this connection.
     */
    public function installed(): bool
    {
        $schema = $this->connection->getSchemaBuilder();

        return $schema->hasTable(self::Runs) && $schema->hasTable(self::Checkpoints);
    }

    /**
     * Refuse when the adoption migration has not run on this connection.
     */
    public function assertInstalled(): void
    {
        if (! $this->installed()) {
            throw new AdoptionException("Adoption tables are missing on connection [{$this->connection->getName()}]. Enable nvl-core.adoption.migrations.enabled or publish nvl-core-migrations, then migrate that connection.");
        }
    }

    /**
     * @return Run|null
     */
    public function find(string $id): ?array
    {
        return $this->run($this->connection->table(self::Runs)->useWritePdo()->where('id', $id)->first());
    }

    /**
     * @return Run|null
     */
    public function findByScope(string $scopeHash): ?array
    {
        return $this->run($this->connection->table(self::Runs)->useWritePdo()->where('scope_hash', $scopeHash)->first());
    }

    /**
     * Lock the run row until the surrounding transaction ends.
     *
     * @return Run
     */
    public function lock(string $id): array
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new AdoptionException('Adoption runs can only be locked inside a destination transaction.');
        }

        return $this->run($this->connection->table(self::Runs)->where('id', $id)->lockForUpdate()->first())
            ?? throw new AdoptionException("Adoption run [{$id}] does not exist.");
    }

    /**
     * Insert a planned run unless one already exists for the scope; return the new identifier or null.
     *
     * @param  array{type: string, id: string}  $actor
     */
    public function create(string $scopeHash, AdoptionPlan $plan, array $actor): ?string
    {
        $id = Str::lower((string) Str::uuid7());
        $now = Date::now();
        $inserted = $this->connection->table(self::Runs)->insertOrIgnore([
            'id' => $id,
            'scope_hash' => $scopeHash,
            'package' => $plan->package,
            'fingerprint' => $plan->fingerprint,
            'adapter_version' => $plan->adapterVersion,
            'status' => AdoptionProgress::Planned,
            'plan' => json_encode($plan->metadata(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'actor_type' => $actor['type'],
            'actor_id' => $actor['id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $inserted === 1 ? $id : null;
    }

    /**
     * @param  array{status?: string, verification_digest?: ?string, verified_at?: \DateTimeInterface|null, activated_at?: \DateTimeInterface|null}  $attributes
     */
    public function update(string $id, array $attributes): void
    {
        $this->connection->table(self::Runs)->where('id', $id)->update([...$attributes, 'updated_at' => Date::now()]);
    }

    /**
     * Delete a run and its checkpoints inside the caller's transaction. Staged destination rows are not touched.
     */
    public function delete(string $id): void
    {
        $this->connection->table(self::Checkpoints)->where('run_id', $id)->delete();
        $this->connection->table(self::Runs)->where('id', $id)->delete();
    }

    /**
     * Return the run's checkpoints keyed by step.
     *
     * @return array<string, Checkpoint>
     */
    public function checkpoints(string $runId): array
    {
        $checkpoints = [];
        foreach ($this->connection->table(self::Checkpoints)->useWritePdo()->where('run_id', $runId)->get() as $row) {
            $checkpoint = $this->checkpoint($row);
            $checkpoints[$checkpoint['step']] = $checkpoint;
        }

        return $checkpoints;
    }

    /**
     * Insert or replace one step's checkpoint inside the batch transaction.
     *
     * @param  Checkpoint  $checkpoint
     */
    public function saveCheckpoint(string $runId, array $checkpoint): void
    {
        $this->connection->table(self::Checkpoints)->upsert([[
            'run_id' => $runId,
            'step' => $checkpoint['step'],
            'cursor' => json_encode(['position' => $checkpoint['cursor'], 'complete' => $checkpoint['complete']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'processed' => $checkpoint['processed'],
            'batch_digest' => $checkpoint['batch_digest'],
            'output_revision' => $checkpoint['output_revision'],
        ]], ['run_id', 'step'], ['cursor', 'processed', 'batch_digest', 'output_revision']);
    }

    /**
     * Whether an activated run exists for the package on this connection.
     */
    public function hasActiveRun(string $package): bool
    {
        return $this->connection->table(self::Runs)->useWritePdo()
            ->where('package', $package)->where('status', AdoptionProgress::Active)->whereNotNull('activated_at')->exists();
    }

    /**
     * @return Run|null
     */
    private function run(mixed $row): ?array
    {
        if (! $row instanceof stdClass) {
            return null;
        }

        return [
            'id' => $this->string($row->id ?? null),
            'scope_hash' => $this->string($row->scope_hash ?? null),
            'package' => $this->string($row->package ?? null),
            'fingerprint' => $this->string($row->fingerprint ?? null),
            'adapter_version' => $this->string($row->adapter_version ?? null),
            'status' => $this->string($row->status ?? null),
            'verification_digest' => $this->nullableString($row->verification_digest ?? null),
            'verified_at' => $this->nullableString($row->verified_at ?? null),
            'activated_at' => $this->nullableString($row->activated_at ?? null),
            'options_digest' => $this->optionsDigest($row->plan ?? null),
        ];
    }

    /**
     * Read the reviewed options digest from the run's stored plan metadata.
     */
    private function optionsDigest(mixed $plan): ?string
    {
        if (! is_string($plan)) {
            return null;
        }
        try {
            $metadata = json_decode($plan, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AdoptionException('An adoption run plan is not valid JSON.', previous: $exception);
        }

        return is_array($metadata) && is_string($metadata['options_digest'] ?? null) ? $metadata['options_digest'] : null;
    }

    /**
     * @return Checkpoint
     */
    private function checkpoint(mixed $row): array
    {
        if (! $row instanceof stdClass || ! is_string($row->cursor ?? null) || ! is_numeric($row->processed ?? null)) {
            throw new AdoptionException('An adoption checkpoint row is malformed.');
        }
        try {
            $state = json_decode($row->cursor, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AdoptionException('An adoption checkpoint cursor is not valid JSON.', previous: $exception);
        }
        $position = is_array($state) ? ($state['position'] ?? null) : null;
        if (! is_array($state) || ! is_bool($state['complete'] ?? null) || ($position !== null && ! is_array($position))) {
            throw new AdoptionException('An adoption checkpoint cursor is malformed.');
        }
        $cursor = null;
        if ($position !== null) {
            $cursor = [];
            foreach ($position as $column => $value) {
                if (! is_string($column) || (! is_int($value) && ! is_string($value))) {
                    throw new AdoptionException('An adoption checkpoint cursor is malformed.');
                }
                $cursor[$column] = $value;
            }
        }

        return [
            'step' => $this->string($row->step ?? null),
            'cursor' => $cursor,
            'complete' => $state['complete'],
            'processed' => (int) $row->processed,
            'batch_digest' => $this->string($row->batch_digest ?? null),
            'output_revision' => $this->nullableString($row->output_revision ?? null),
        ];
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new AdoptionException('An adoption run row is malformed.');
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : $this->string($value);
    }
}
