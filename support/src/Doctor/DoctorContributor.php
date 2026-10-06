<?php

declare(strict_types=1);

namespace Nvl\Support\Doctor;

/**
 * Supplies package-owned, read-only checks to the consumer Doctor.
 *
 * @api
 */
interface DoctorContributor
{
    /**
     * Return the stable Composer package identity for these checks.
     */
    public function package(): string;

    /**
     * Inspect the loaded package without changing application state.
     *
     * @return iterable<DoctorCheck>
     */
    public function inspect(): iterable;
}
