<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Nvl\Support\Integrations\OptionalIntegration;

it('selects optional adapters only from loaded providers', function (mixed $flag, bool $loaded, bool $enabled): void {
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('getLoadedProviders')->andReturn(['OptionalProvider' => $loaded]);
    $integration = new OptionalIntegration(new Repository(['feature' => ['enabled' => $flag]]), $app);

    expect($integration->enabled('feature.enabled', 'OptionalProvider'))->toBe($enabled);
})->with([[null, false, false], [null, true, true], [false, true, false], [true, true, true]]);

it('fails clearly when explicitly selected optional adapters are unavailable', function (): void {
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('getLoadedProviders')->andReturn([]);
    $integration = new OptionalIntegration(new Repository(['feature' => ['enabled' => true]]), $app);

    expect(fn () => $integration->enabled('feature.enabled', 'OptionalProvider'))
        ->toThrow(InvalidArgumentException::class, 'feature.enabled requires the loaded provider [OptionalProvider]')
        ->and($integration->check('feature.enabled', 'OptionalProvider')['severity'])->toBe('error');
});

it('reports absent automatic adapters as informational', function (): void {
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('getLoadedProviders')->andReturn([]);
    $integration = new OptionalIntegration(new Repository, $app);

    expect($integration->check('feature.enabled', 'OptionalProvider'))
        ->toMatchArray(['key' => 'feature.enabled', 'passed' => true, 'severity' => 'info']);
});

it('rejects invalid optional switch values', function (): void {
    $app = Mockery::mock(Application::class);
    $integration = new OptionalIntegration(new Repository(['feature' => ['enabled' => 'yes']]), $app);

    expect(fn () => $integration->enabled('feature.enabled', 'OptionalProvider'))
        ->toThrow(InvalidArgumentException::class, 'must be true, false, or null');
});
