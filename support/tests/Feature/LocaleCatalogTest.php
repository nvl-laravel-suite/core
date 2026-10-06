<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Support\Locales\ApplicationLocaleCatalog;
use Nvl\Support\Locales\LocaleCatalogDiagnostics;
use Nvl\Support\Locales\LocaleCode;
use Nvl\Support\Providers\LocaleServiceProvider;

it('derives the standalone content catalog from distinct valid application locales', function (): void {
    $configuration = new Repository(['app' => ['locale' => 'fr_ca', 'fallback_locale' => 'EN_gb']]);
    $catalog = new ApplicationLocaleCatalog($configuration);

    expect($catalog->supported())->toBe(['fr-CA', 'en-GB'])
        ->and($catalog->default())->toBe('fr-CA')
        ->and($catalog->fallbacks())->toBe(['en-GB'])
        ->and($catalog->chain('FR_ca'))->toBe(['fr-CA', 'en-GB']);

    $configuration->set('app.locale', 'en_GB');
    expect($catalog->supported())->toBe(['en-GB']);

    $configuration->set('app.locale', 'bad locale');
    expect($catalog->default())->toBe('en-GB');
});

it('shares regional normalization and validates locale shape', function (): void {
    expect(LocaleCode::normalize(' ZH_hANT_tw '))->toBe('zh-Hant-TW')
        ->and((new LocaleCode('es_419'))->value)->toBe('es-419')
        ->and(LocaleCode::isValid('bad locale'))->toBeFalse();

    expect(fn () => new LocaleCode('bad locale'))->toThrow(InvalidArgumentException::class);
});

it('uses the deprecated primitives catalog only for the standalone default', function (): void {
    $configuration = new Repository([
        'app' => ['locale' => 'fr', 'fallback_locale' => 'en'],
        'nvl-primitives' => ['locales' => ['supported' => ['BG_bg', 'en']]],
    ]);
    $catalog = new ApplicationLocaleCatalog($configuration);

    expect($catalog->supported())->toBe(['bg-BG', 'en'])
        ->and($catalog->default())->toBe('en')
        ->and((new LocaleCatalogDiagnostics($configuration, $catalog))->inspect()['warnings'])
        ->toContain('nvl-primitives.locales is deprecated; bind LocaleCatalog or configure nvl-translatable.locales.');

    $configuration->set('nvl-primitives.locales.supported', ['en', 'EN']);
    expect(fn () => $catalog->supported())->toThrow(InvalidArgumentException::class);
});

it('keeps host catalog bindings when the default locale provider registers', function (): void {
    $application = new Application;
    $application->instance('config', new Repository(['app' => ['locale' => 'de', 'fallback_locale' => 'en']]));
    $host = new ApplicationLocaleCatalog(new Repository(['app' => ['locale' => 'fr', 'fallback_locale' => 'fr']]));
    $application->instance(LocaleCatalog::class, $host);

    (new LocaleServiceProvider($application))->register();

    expect($application->make(LocaleCatalog::class))->toBe($host);
});

it('uses an explicit Core catalog before legacy primitive configuration and preserves empty fallbacks', function (): void {
    $configuration = new Repository([
        'app' => ['locale' => 'fr', 'fallback_locale' => 'de'],
        'nvl-core' => ['locales' => ['supported' => ['zh', 'zh_hant_tw', 'en'], 'default' => 'zh', 'fallback' => ['en']]],
        'nvl-primitives' => ['locales' => ['supported' => ['fr']]],
    ]);
    $catalog = new ApplicationLocaleCatalog($configuration);

    expect($catalog->supported())->toBe(['zh', 'zh-Hant-TW', 'en'])
        ->and($catalog->default())->toBe('zh')
        ->and($catalog->chain('zh_HANT_tw'))->toBe(['zh-Hant-TW', 'zh', 'en']);

    $configuration->set('nvl-core.locales.fallback', []);
    expect($catalog->fallbacks())->toBe([]);

    $configuration->set('nvl-core.locales.fallback', ['fr']);
    expect(fn () => $catalog->fallbacks())->toThrow(InvalidArgumentException::class);
});
