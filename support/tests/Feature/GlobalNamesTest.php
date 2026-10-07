<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\URL;
use Nvl\Support\Globals\GlobalNames;

it('registers legacy aliases only for explicitly selected packages and preserves collisions', function (): void {
    $names = new GlobalNames(config());
    $aliases = ['settings' => 'host.settings'];
    $exists = static fn (string $name): bool => array_key_exists($name, $aliases);
    $install = function (string $name) use (&$aliases): void {
        $aliases[$name] = 'nvl.settings';
    };
    expect($names->register('settings', 'container', 'settings', 'nvl.settings', $exists, $install))->toBeFalse();
    config(['nvl-core.compatibility.global_aliases' => ['settings']]);
    expect($names->register('settings', 'container', 'settings', 'nvl.settings', $exists, $install))->toBeFalse()
        ->and($aliases)->toBe(['settings' => 'host.settings'])
        ->and($names->diagnostics()[0]->severity)->toBe('warning')
        ->and($names->diagnostics()[0]->passed)->toBeFalse();
});

it('installs selected free aliases once without reclaiming names on subsequent registration', function (): void {
    config(['nvl-core.compatibility.global_aliases' => ['seo']]);
    $names = new GlobalNames(config());
    $registrations = [];
    $exists = function (string $name) use (&$registrations): bool {
        return array_key_exists($name, $registrations);
    };
    $install = function (string $name) use (&$registrations): void {
        $registrations[$name] = ($registrations[$name] ?? 0) + 1;
    };
    expect($names->register('seo', 'blade', 'seo', 'nvlSeo', $exists, $install))->toBeTrue()
        ->and($names->register('seo', 'blade', 'seo', 'nvlSeo', $exists, $install))->toBeTrue()
        ->and($registrations)->toBe(['seo' => 1]);
    expect($names->diagnostics())->toHaveCount(2)
        ->and($names->diagnostics()[0]->message)->toContain('nvlSeo', 'next major');
});

it('reports selected compatibility modes even when cached routes skip registration', function (): void {
    config(['nvl-core.compatibility.legacy_routes' => ['media']]);
    $names = new GlobalNames(config());
    $checks = $names->diagnostics();

    expect($checks)->toHaveCount(1)
        ->and($checks[0]->key)->toBe('globals.compatibility.legacy_routes.media')
        ->and($checks[0]->severity)->toBe('warning')
        ->and($checks[0]->passed)->toBeFalse()
        ->and($checks[0]->message)->toContain('major 6');
});

it('rejects malformed compatibility lists and refuses the generic config publish group', function (): void {
    $names = new GlobalNames(config());
    config(['nvl-core.compatibility.global_aliases' => true]);
    expect(fn () => $names->enabled('seo'))->toThrow(InvalidArgumentException::class);
    config(['nvl-core.compatibility.global_aliases' => ['media']]);
    $called = false;
    expect($names->register('media', 'publish', 'config', 'nvl-media-config', static fn (): bool => false,
        function () use (&$called): void {
            $called = true;
        }))->toBeFalse()
        ->and($called)->toBeFalse();
});

it('preserves signed legacy URLs with the original handler and signature checks', function (): void {
    config(['nvl-core.compatibility.legacy_routes' => ['media']]);
    $router = app(Router::class);
    $canonical = $router->get('nvl/media/private/{owner}/{media}', static fn (): string => 'owned')
        ->middleware(ValidateSignature::class)->name('nvl.media.private.show');
    $router->getRoutes()->refreshNameLookups();
    $names = new GlobalNames(config());
    expect($names->routes('media', $router, ['nvl.media.private.show' => ['uri' => 'media/private/{owner}/{media}', 'name' => 'media.private.show']]))->toBe(1);
    $router->getRoutes()->refreshNameLookups();
    $legacy = $router->getRoutes()->getByName('media.private.show');
    expect($legacy->getActionName())->toBe($canonical->getActionName())
        ->and($legacy->gatherMiddleware())->toBe($canonical->gatherMiddleware());
    $url = URL::temporarySignedRoute('media.private.show', now()->addMinute(), ['owner' => 'owner', 'media' => 'asset']);
    $this->get($url)->assertOk()->assertSee('owned');
    $this->get(str_replace('/owner/', '/other/', $url))->assertForbidden();
    $this->travel(2)->minutes();
    $this->get($url)->assertForbidden();
});

it('keeps host route names and method paths untouched when legacy route compatibility collides', function (): void {
    config(['nvl-core.compatibility.legacy_routes' => ['media']]);
    $router = app(Router::class);
    $router->get('nvl/media/assets/{media}', static fn (): string => 'nvl')->name('nvl.media.assets.show');
    $host = $router->get('media/assets/{media}', static fn (): string => 'host')->name('host.media');
    $router->getRoutes()->refreshNameLookups();
    $names = new GlobalNames(config());
    expect($names->routes('media', $router, ['nvl.media.assets.show' => ['uri' => 'media/assets/{media}', 'name' => 'media.assets.show']]))->toBe(0)
        ->and($router->getRoutes()->getByName('host.media'))->toBe($host)
        ->and($names->diagnostics()[0]->message)->toContain('collision');
    $this->get('/media/assets/example')->assertSee('host');
});

it('preserves canonical host route names and method paths during package loading', function (): void {
    $router = app(Router::class);
    $hostPath = $router->get('nvl/media/assets/{media}', static fn (): string => 'host-path')->name('host.asset');
    $hostName = $router->get('host/download', static fn (): string => 'host-name')->name('nvl.media.private.show');
    $names = new GlobalNames(config());
    $names->loadRoutes('media', $router, function () use ($router): void {
        $router->get('nvl/media/assets/{media}', static fn (): string => 'nvl')->name('nvl.media.assets.show');
        $router->get('nvl/media/private/{media}', static fn (): string => 'nvl')->name('nvl.media.private.show');
        $router->get('nvl/media/status', static fn (): string => 'owned')->name('nvl.media.status');
    });
    expect($router->getRoutes()->getByName('host.asset'))->toBe($hostPath)
        ->and($router->getRoutes()->getByName('nvl.media.private.show'))->toBe($hostName)
        ->and($names->diagnostics())->toHaveCount(2);
    $this->get('/nvl/media/assets/example')->assertSee('host-path');
    $this->get('/host/download')->assertSee('host-name');
    $this->get('/nvl/media/status')->assertSee('owned');
});

it('installs only the selected canonical route family as native legacy routes', function (): void {
    config(['nvl-core.compatibility.legacy_routes' => ['media']]);
    $router = app(Router::class);
    $canonical = $router->get('nvl/media/assets/{media}', static fn (): string => 'asset')->name('nvl.media.assets.probe');
    $router->get('host/outside', static fn (): string => 'host')->name('nvl.media.assets.outside');
    $router->get('nvl/media/assets/unnamed', static fn (): string => 'unnamed');
    $names = new GlobalNames(config());
    $names->bootRoutes($this->app);
    $legacy = $router->getRoutes()->getByName('media.assets.probe');
    expect($legacy)->not->toBeNull()
        ->and($legacy->uri())->toBe('media/assets/{media}')
        ->and($legacy->getActionName())->toBe($canonical->getActionName())
        ->and($router->getRoutes()->getByName('media.assets.outside'))->toBeNull();
    $this->get('/media/assets/example')->assertOk()->assertSee('asset');
});
