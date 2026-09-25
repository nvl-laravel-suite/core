<?php

declare(strict_types=1);

namespace Nvl\Data\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Nvl\Data\Services\TypeScriptConfigurator;
use ReflectionClass;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;

/**
 * Supplies a ready-to-use Spatie transformer configuration for standalone installs.
 */
final class DataTypeScriptTransformerServiceProvider extends ServiceProvider
{
    /**
     * Bind the lazily constructed transformer configuration.
     */
    public function register(): void
    {
        $this->app->singleton(
            TypeScriptTransformerConfig::class,
            static function (Application $app): TypeScriptTransformerConfig {
                $providerPath = (new ReflectionClass(self::class))->getFileName();

                if (! is_string($providerPath)) {
                    throw new LogicException('The NVL Data service provider must be file-backed.');
                }

                $factory = (new TypeScriptTransformerConfigFactory)
                    ->configPath($providerPath);

                $app->make(TypeScriptConfigurator::class)->configure($factory);

                return $factory->get();
            },
        );
    }
}
