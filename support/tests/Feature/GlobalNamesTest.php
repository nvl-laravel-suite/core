<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Loader;
use Nvl\Support\Globals\GlobalNames;

it('preserves host canonical registrations and reports their collisions', function (): void {
    $names = new GlobalNames(config());
    $installed = false;
    expect($names->reserve('settings', 'container', 'nvl.settings', static fn (string $name): bool => true,
        function (string $name) use (&$installed): void {
            $installed = true;
        }))->toBeFalse()
        ->and($installed)->toBeFalse()
        ->and($names->diagnostics()[0]->key)->toBe('globals.settings.container.nvl.settings')
        ->and($names->diagnostics()[0]->passed)->toBeFalse();
});

it('installs free canonical names once per application boot', function (): void {
    $names = new GlobalNames(config());
    $registrations = [];
    $exists = static fn (string $name): bool => false;
    $install = function (string $name) use (&$registrations): void {
        $registrations[] = $name;
    };
    expect($names->reserve('seo', 'blade', 'nvlSeo', $exists, $install))->toBeTrue()
        ->and($names->reserve('seo', 'blade', 'nvlSeo', $exists, $install))->toBeTrue()
        ->and($registrations)->toBe(['nvlSeo'])
        ->and($names->diagnostics())->toBe([]);
});

it('appends only when explicitly requested for an owned canonical name', function (): void {
    $names = new GlobalNames(config());
    $registrations = [];
    $install = function (string $name) use (&$registrations): void {
        $registrations[] = $name;
    };
    $names->reserve('seo', 'publish', 'nvl-seo-config', static fn (string $name): bool => false, $install);
    $names->reserve('seo', 'publish', 'nvl-seo-config', static fn (string $name): bool => true, $install, append: true);
    expect($registrations)->toBe(['nvl-seo-config', 'nvl-seo-config']);
});

it('persists sorted collision diagnostics across service reconstruction', function (): void {
    $names = new GlobalNames(config());
    foreach (['settings', 'auth'] as $package) {
        $names->reserve($package, 'container', 'nvl.'.$package, static fn (string $name): bool => true, static function (string $name): void {});
    }
    $checks = (new GlobalNames(config()))->diagnostics();
    expect(array_map(static fn ($check): string => $check->key, $checks))->toBe([
        'globals.auth.container.nvl.auth', 'globals.settings.container.nvl.settings',
    ]);
});

it('rejects malformed persisted diagnostic declarations', function (): void {
    config(['nvl-core.configuration.global_names' => true]);
    expect(fn (): array => (new GlobalNames(config()))->diagnostics())->toThrow(InvalidArgumentException::class);
});

it('does not retain host values in collision diagnostics', function (): void {
    $names = new GlobalNames(config());
    $names->reserve('auth', 'container', 'nvl.auth', static fn (string $name): bool => true, static function (string $name): void {});
    expect(config('nvl-core.configuration.global_names'))->toHaveKeys(['auth.container.nvl.auth'])
        ->and($names->diagnostics()[0]->message)->toContain('host registration was preserved');
});

it('registers canonical translation namespaces without replacing host hints', function (): void {
    $loader = Mockery::mock(Loader::class);
    $loader->shouldReceive('namespaces')->once()->andReturn(['nvl-seo' => '/host/translations']);
    $loader->shouldNotReceive('addNamespace');
    $names = new GlobalNames(config());
    $names->translations('seo', '/package/translations', $loader);
    expect($names->diagnostics()[0]->key)->toBe('globals.seo.translations.nvl-seo');
});

it('registers free canonical translation namespaces', function (): void {
    $loader = Mockery::mock(Loader::class);
    $loader->shouldReceive('namespaces')->once()->andReturn([]);
    $loader->shouldReceive('addNamespace')->once()->with('nvl-seo', '/package/translations');
    $names = new GlobalNames(config());
    $names->translations('seo', '/package/translations', $loader);
    expect($names->diagnostics())->toBe([]);
});
