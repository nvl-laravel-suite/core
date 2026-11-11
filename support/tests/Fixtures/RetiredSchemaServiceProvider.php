<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Support\ServiceProvider;
use Nvl\Support\Console\Commands\SchemaPreflightCommand;
use Nvl\Support\Console\Commands\SchemaUpgradeCommand;
use Nvl\Support\Schema\SchemaMigrationEvents;

/** Registers the deprecated schema tooling for its own tests only; nothing in family 7 registers it, and Core never discovers this fixture. */
final class RetiredSchemaServiceProvider extends ServiceProvider
{
    /** Explicitly enable only the retired schema fixtures that still exercise this source. */
    public function boot(Dispatcher $events): void
    {
        $events->listen(MigrationStarted::class, [SchemaMigrationEvents::class, 'before']);
        $this->commands([SchemaPreflightCommand::class, SchemaUpgradeCommand::class]);
    }
}
