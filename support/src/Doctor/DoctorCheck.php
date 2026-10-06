<?php

declare(strict_types=1);

namespace Nvl\Support\Doctor;

use InvalidArgumentException;

/**
 * Describes one immutable, read-only installation diagnostic.
 *
 * @api
 */
final readonly class DoctorCheck
{
    /**
     * Create a diagnostic with a stable key and actionable message.
     */
    public function __construct(
        public string $key,
        public string $severity,
        public bool $passed,
        public string $message,
    ) {
        if ($key === '' || $message === '' || ! in_array($severity, ['error', 'warning', 'info'], true)) {
            throw new InvalidArgumentException('Doctor checks require a key, message, and error, warning, or info severity.');
        }
    }

    /**
     * Determine whether the check fails the selected gate.
     */
    public function fails(bool $strict): bool
    {
        return ! $this->passed && ($this->severity === 'error' || ($strict && $this->severity === 'warning'));
    }

    /**
     * Serialize the stable public report representation.
     *
     * @return array{package: string, key: string, severity: string, result: string, message: string}
     */
    public function toArray(string $package): array
    {
        return [
            'package' => $package,
            'key' => $this->key,
            'severity' => $this->severity,
            'result' => $this->passed ? 'pass' : 'fail',
            'message' => $this->message,
        ];
    }
}
