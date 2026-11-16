<?php

declare(strict_types=1);

namespace Nvl\Support\Adoption;

/**
 * One keyset page of mapped rows for a step. Rows stay in memory; only the digest, cursor and count are stored.
 *
 * @api
 *
 * @phpstan-import-type Cursor from AdoptionStep
 */
final readonly class AdoptionBatch
{
    /** Canonical digest of the step, cursors and mapped rows. */
    public string $digest;

    /**
     * @param  Cursor|null  $cursorIn  The checkpoint cursor this batch was read after; null for the first batch
     * @param  Cursor|null  $cursorOut  The keyset position of the last source row read; equal to the input when empty
     * @param  list<array<string, mixed>>  $rows  Mapped destination rows
     * @param  bool  $complete  True when no source rows remain after this batch
     */
    public function __construct(
        public string $step,
        public ?array $cursorIn,
        public ?array $cursorOut,
        public array $rows,
        public bool $complete,
    ) {
        $this->digest = AdoptionPlan::digest([$step, $cursorIn, $cursorOut, $rows]);
    }
}
