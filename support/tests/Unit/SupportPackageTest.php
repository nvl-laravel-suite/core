<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Nvl\Support\Providers\SupportServiceProvider;
use SplFileInfo;

it('is auto-discoverable through Core and can boot its provider in isolation', function (): void {
    $packageRoot = dirname(__DIR__, 2);
    $coreRoot = dirname(__DIR__, 3);
    $filesystem = new Filesystem;
    /** @var array<string, mixed> $manifest */
    $manifest = json_decode(
        $filesystem->get($coreRoot.'/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $provider = new SupportServiceProvider(new Application($packageRoot));
    $provider->boot();

    expect($manifest['extra']['laravel']['providers'] ?? [])
        ->toContain(SupportServiceProvider::class)
        ->and($provider)
        ->toBeInstanceOf(SupportServiceProvider::class);
});

it('publishes its packaged agent guidance through the documented tag', function (): void {
    $packageRoot = dirname(__DIR__, 2);
    $application = new Application($packageRoot);
    $provider = new SupportServiceProvider($application);
    $provider->boot();

    $publishPaths = SupportServiceProvider::pathsToPublish(
        SupportServiceProvider::class,
        'support-skills',
    );
    $publishedSource = array_key_first($publishPaths);

    expect($publishPaths)
        ->toHaveCount(1)
        ->and($publishedSource)
        ->toBeString()
        ->and(realpath($publishedSource))
        ->toBe(realpath($packageRoot.'/resources/boost/skills'))
        ->and(array_values($publishPaths))
        ->toBe([$application->basePath('.agents/skills')]);
});

it('has no runtime boot side effects outside the console', function (): void {
    $packageRoot = dirname(__DIR__, 2);
    $application = new class($packageRoot) extends Application
    {
        /**
         * Report that the isolated application is handling a web request.
         */
        public function runningInConsole(): bool
        {
            return false;
        }
    };
    $publishPathsBeforeBoot = SupportServiceProvider::pathsToPublish(
        SupportServiceProvider::class,
        'support-skills',
    );

    (new SupportServiceProvider($application))->boot();

    expect(SupportServiceProvider::pathsToPublish(
        SupportServiceProvider::class,
        'support-skills',
    ))->toBe($publishPathsBeforeBoot);
});

it('has no runtime or test-harness dependency on another NVL package', function (): void {
    $packageRoot = dirname(__DIR__, 2);
    $coreRoot = dirname(__DIR__, 3);
    $filesystem = new Filesystem;
    /** @var array{require: array<string, string>} $manifest */
    $manifest = json_decode(
        $filesystem->get($coreRoot.'/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $internalDependencies = array_values(array_filter(
        array_keys($manifest['require']),
        static fn (string $dependency): bool => str_starts_with($dependency, 'nvl/'),
    ));
    $testHarness = $filesystem->get($packageRoot.'/tests/TestCase.php');

    expect($internalDependencies)
        ->toBe([])
        ->and($testHarness)
        ->not->toMatch('/^use\s+Nvl\\\\(?!Support\\\\)/m');
});

it('keeps its source boundary minimal and transport-neutral', function (): void {
    $packageRoot = dirname(__DIR__, 2);
    $filesystem = new Filesystem;
    $sourceDirectories = collect($filesystem->directories($packageRoot.'/src'))
        ->map(static fn (string $directory): string => basename($directory))
        ->sort()
        ->values()
        ->all();
    $source = collect($filesystem->allFiles($packageRoot.'/src'))
        ->map(static fn (SplFileInfo $file): string => $file->getContents())
        ->implode("\n");

    expect($sourceDirectories)
        ->toBe(['Config', 'Contracts', 'Exceptions', 'Providers', 'Traits'])
        ->and($source)
        ->not->toMatch('/^use\s+Nvl\\\\(?!Support\\\\)/m')
        ->not->toContain('Illuminate\\Http\\')
        ->not->toMatch('/\b(?:abort|redirect|response)\s*\(/');

    foreach (['config', 'database', 'routes'] as $forbiddenDirectory) {
        expect($packageRoot.'/'.$forbiddenDirectory)->not->toBeDirectory();
    }
});
