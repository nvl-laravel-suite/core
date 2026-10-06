<?php

declare(strict_types=1);

namespace Nvl\Support\Contracts;

/** Versioned, model-free package fact published on its source connection's commit.
 *
 * @api
 */
interface DomainEvent
{
    /** Return the immutable payload schema version. */
    public function schemaVersion(): int;
}
