<?php

declare(strict_types=1);

namespace Nvl\Support\Schema;

use Illuminate\Database\Events\MigrationStarted;
use LogicException;
use ReflectionClass;

/**
 * Checks the exact owned file at Laravel's individual migration execution seam.
 *
 * @deprecated Not registered in family 7: no provider binds, listens to or exposes it. It is retained only for its own tests
 *             (see tests/Fixtures/RetiredSchemaServiceProvider) and may be removed in a later release. Hosts run it on 6.x before upgrading.
 */
final readonly class SchemaMigrationEvents
{
    /** Retain the ownership inventory and selected-file validator. */
    public function __construct(private SchemaMigrationPaths $paths, private SchemaPreflight $preflight) {}

    /** Reject unreconciled owned code before the current migration body executes. */
    public function before(MigrationStarted $event): void
    {
        $path = (new ReflectionClass($event->migration))->getFileName();
        if (! is_string($path) || ($identity = $this->paths->identity($path)) === null) {
            return;
        }
        if (! $identity['current']) {
            throw new LogicException('Replace or archive the declared legacy migration code and reconcile its history before migrating or rolling back.');
        }
        if ($event->method === 'up') {
            $this->preflight->validate([$path]);
        }
    }
}
