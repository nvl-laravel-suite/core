<?php

declare(strict_types=1);

namespace Nvl\Support\Doctor;

use Nvl\Support\Logging\PackageLogger;

/** Inspects package logging policy without constructing or writing to its channels. */
final readonly class PackageLoggingDoctor implements DoctorContributor
{
    /** Retain the stateless policy reader. */
    public function __construct(private PackageLogger $logger) {}

    /** Identify the owning infrastructure package. */
    public function package(): string
    {
        return 'nvl/core';
    }

    /** @return iterable<DoctorCheck> */
    public function inspect(): iterable
    {
        $errors = $this->logger->diagnostics();
        yield new DoctorCheck('logging.policy', 'error', $errors === [], $errors === []
            ? 'Package logging channels and verbosity are configured without stack cycles.'
            : implode(' ', $errors));
    }
}
