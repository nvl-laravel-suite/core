<?php

declare(strict_types=1);

namespace Nvl\Support\Adoption;

/**
 * Snapshot of a run's state and per-step checkpoints.
 *
 * @api
 *
 * @phpstan-import-type Cursor from AdoptionStep
 *
 * @phpstan-type StepProgress array{cursor: Cursor|null, processed: int, expected: int|null, complete: bool}
 */
final readonly class AdoptionProgress
{
    public const string Planned = 'planned';

    public const string Applying = 'applying';

    public const string Applied = 'applied';

    public const string Verified = 'verified';

    public const string Active = 'active';

    public const string Failed = 'failed';

    /**
     * @param  array<string, StepProgress>  $steps  Every plan step in order
     * @param  int  $written  Batches committed by this invocation
     */
    public function __construct(
        public string $run,
        public string $package,
        public string $fingerprint,
        public string $state,
        public array $steps,
        public ?string $verifiedAt = null,
        public ?string $activatedAt = null,
        public int $written = 0,
    ) {}

    /**
     * Whether every step has committed its final batch.
     */
    public function complete(): bool
    {
        foreach ($this->steps as $step) {
            if (! $step['complete']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{run: string, package: string, fingerprint: string, state: string, steps: array<string, StepProgress>, verified_at: ?string, activated_at: ?string, written: int}
     */
    public function toArray(): array
    {
        return [
            'run' => $this->run, 'package' => $this->package, 'fingerprint' => $this->fingerprint, 'state' => $this->state,
            'steps' => $this->steps, 'verified_at' => $this->verifiedAt, 'activated_at' => $this->activatedAt, 'written' => $this->written,
        ];
    }
}
