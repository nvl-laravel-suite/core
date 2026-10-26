<?php

declare(strict_types=1);

namespace Nvl\Data\Services;

use Spatie\TypeScriptTransformer\Support\Loggers\Logger;

/**
 * Tracks transformer warnings and errors while preserving caller output.
 */
final class TypeScriptDiagnosticsLogger implements Logger
{
    private bool $hasWarnings = false;

    private bool $hasErrors = false;

    /**
     * Create a diagnostic logger around the caller's logger.
     */
    public function __construct(private readonly Logger $logger) {}

    /**
     * Forward one debug message.
     */
    public function debug(mixed $item, ?string $title = null): void
    {
        $this->logger->debug($item, $title);
    }

    /**
     * Forward one informational message.
     */
    public function info(mixed $item, ?string $title = null): void
    {
        $this->logger->info($item, $title);
    }

    /**
     * Record and forward one warning.
     */
    public function warning(mixed $item, ?string $title = null): void
    {
        $this->hasWarnings = true;
        $this->logger->warning($item, $title);
    }

    /**
     * Record and forward one error.
     */
    public function error(mixed $item, ?string $title = null): void
    {
        $this->hasErrors = true;
        $this->logger->error($item, $title);
    }

    /**
     * Determine whether the transformer emitted a non-success diagnostic.
     */
    public function failed(): bool
    {
        return $this->hasWarnings || $this->hasErrors;
    }
}
