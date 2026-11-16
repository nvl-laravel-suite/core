<?php

declare(strict_types=1);

namespace Nvl\Support\Adoption;

/**
 * Runs one package adoption through its reviewed plan, checkpointed apply, verify and activate phases.
 *
 * @api
 */
interface Adoption
{
    /**
     * Review the source graph and its collisions without writing anything.
     */
    public function plan(): AdoptionPlan;

    /**
     * Stage the reviewed plan in committed batches; pass the existing run identifier to resume it.
     */
    public function apply(string $fingerprint, int $batchSize = 100, ?string $run = null): AdoptionProgress;

    /**
     * Scan committed source and destination independently and record the verification digest.
     */
    public function verify(): AdoptionVerification;

    /**
     * Re-verify the locked run and mark it active; this changes no configuration.
     */
    public function activate(string $fingerprint): AdoptionProgress;
}
