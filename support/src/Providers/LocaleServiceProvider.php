<?php

declare(strict_types=1);

namespace Nvl\Support\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Foundation\Application as FoundationApplication;
use Illuminate\Support\ServiceProvider;
use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Support\Locales\ApplicationLocaleCatalog;

/** Registers the standalone locale catalog while preserving host implementations. */
final class LocaleServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /** Register application-backed content locale defaults. */
    public function register(): void
    {
        if ($this->app instanceof FoundationApplication
            && ($this->app->getDeferredServices()[LocaleCatalog::class] ?? null) === self::class) {
            $deferred = $this->app->getDeferredServices();
            unset($deferred[LocaleCatalog::class]);
            $this->app->setDeferredServices($deferred);
        }

        $this->app->singletonIf(LocaleCatalog::class, ApplicationLocaleCatalog::class);
    }

    /**
     * Declare the sole service supplied through native package discovery.
     *
     * @return list<class-string>
     */
    public function provides(): array
    {
        return [LocaleCatalog::class];
    }
}
