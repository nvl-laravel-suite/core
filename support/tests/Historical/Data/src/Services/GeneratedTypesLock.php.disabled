<?php

declare(strict_types=1);

namespace Nvl\Data\Services;

use Closure;
use Illuminate\Filesystem\LockableFile;

/**
 * Serializes generation and protects the short artifact-publication boundary.
 */
final readonly class GeneratedTypesLock
{
    /**
     * Run one generation exclusively and fail fast when another generation is active.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function generate(Closure $callback): mixed
    {
        return $this->withLock(
            storage_path('app/nvl-data/locks/generation.lock'),
            shared: false,
            block: false,
            callback: $callback,
        );
    }

    /**
     * Read one published artifact set without queueing requests behind publication.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function read(Closure $callback): mixed
    {
        return $this->withLock(
            storage_path('app/nvl-data/locks/publication.lock'),
            shared: true,
            block: false,
            callback: $callback,
        );
    }

    /**
     * Publish one artifact set while readers are excluded.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function publish(Closure $callback): mixed
    {
        return $this->withLock(
            storage_path('app/nvl-data/locks/publication.lock'),
            shared: false,
            block: true,
            callback: $callback,
        );
    }

    /**
     * Execute a callback while holding one local filesystem lock.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withLock(
        string $path,
        bool $shared,
        bool $block,
        Closure $callback,
    ): mixed {
        $lock = new LockableFile($path, 'c+');

        try {
            if ($shared) {
                $lock->getSharedLock($block);
            } else {
                $lock->getExclusiveLock($block);
            }

            return $callback();
        } finally {
            $lock->close();
        }
    }
}
