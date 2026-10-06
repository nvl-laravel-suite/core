<?php

declare(strict_types=1);

namespace Nvl\Support\Providers;

use Illuminate\Support\ServiceProvider;
use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Support\Locales\ApplicationLocaleCatalog;

/** Registers the standalone locale catalog while preserving host implementations. */
final class LocaleServiceProvider extends ServiceProvider
{
    /** Register application-backed content locale defaults. */
    public function register(): void
    {
        $this->app->singletonIf(LocaleCatalog::class, ApplicationLocaleCatalog::class);
    }
}
