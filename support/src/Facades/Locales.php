<?php

declare(strict_types=1);

namespace Nvl\Support\Facades;

use Illuminate\Support\Facades\Facade;
use Nvl\Support\Contracts\LocaleCatalog;

/**
 * Exposes the locale contract to declarative model definitions and validation rules.
 *
 * @method static list<string> supported()
 * @method static string default()
 * @method static list<string> fallbacks()
 * @method static string normalize(string $locale)
 * @method static bool supports(string $locale)
 * @method static string assertSupported(string $locale)
 * @method static list<string> chain(string $requestedLocale, list<mixed> $additionalFallbacks = [])
 */
final class Locales extends Facade
{
    /** Return the shared catalog container binding. */
    protected static function getFacadeAccessor(): string
    {
        return LocaleCatalog::class;
    }
}
