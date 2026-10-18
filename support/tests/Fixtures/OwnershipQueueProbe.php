<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;

/** Records native restoration and failure execution before ownership admission. */
final class OwnershipQueueProbe implements ShouldQueue
{
    public static int $restored = 0;

    public static int $handled = 0;

    public static int $failed = 0;

    /** Observe native object restoration. */
    public function __wakeup(): void
    {
        self::$restored++;
    }

    /** Observe execution. */
    public function handle(): void
    {
        self::$handled++;
    }

    /** Observe a terminal callback. */
    public function failed(?\Throwable $exception): void
    {
        self::$failed++;
    }
}
