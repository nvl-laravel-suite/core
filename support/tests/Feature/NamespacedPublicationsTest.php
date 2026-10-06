<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Nvl\Support\Traits\RegistersNamespacedResources;

/** Exercises publication registration without booting optional domain services. */
final class NamespacedPublicationProvider extends ServiceProvider
{
    use RegistersNamespacedResources;

    /** Declare the fixture's package ownership. */
    protected function publicationPackage(): string
    {
        return 'media';
    }

    /** Publish the same resources used by old package providers. */
    public function boot(): void
    {
        $this->publishes(['/nvl/media/config.php' => '/host/config/nvl-media.php'], ['media-config', 'config']);
    }
}

it('publishes canonical resource groups while preserving host generic groups', function (): void {
    $groups = ServiceProvider::$publishGroups;
    $providers = ServiceProvider::$publishes;
    ServiceProvider::$publishGroups['config'] = ['/host/foreign.php' => '/host/config/foreign.php'];
    ServiceProvider::$publishGroups['media-config'] = ['/host/media.php' => '/host/config/media.php'];
    try {
        (new NamespacedPublicationProvider(app()))->boot();
        expect(ServiceProvider::pathsToPublish(null, 'nvl-media-config'))->toBe(['/nvl/media/config.php' => '/host/config/nvl-media.php'])
            ->and(ServiceProvider::pathsToPublish(null, 'media-config'))->toBe(['/host/media.php' => '/host/config/media.php'])
            ->and(ServiceProvider::pathsToPublish(null, 'config'))->toBe(['/host/foreign.php' => '/host/config/foreign.php']);
    } finally {
        ServiceProvider::$publishGroups = $groups;
        ServiceProvider::$publishes = $providers;
    }
});

it('allows explicit free legacy publish tags but never the aggregate config group', function (): void {
    $groups = ServiceProvider::$publishGroups;
    $providers = ServiceProvider::$publishes;
    unset(ServiceProvider::$publishGroups['media-config']);
    ServiceProvider::$publishGroups['config'] = ['/host/foreign.php' => '/host/config/foreign.php'];
    config(['nvl-core.compatibility.global_aliases' => ['media']]);
    try {
        (new NamespacedPublicationProvider(app()))->boot();
        expect(ServiceProvider::pathsToPublish(null, 'media-config'))->toBe(ServiceProvider::pathsToPublish(null, 'nvl-media-config'))
            ->and(ServiceProvider::pathsToPublish(null, 'config'))->toBe(['/host/foreign.php' => '/host/config/foreign.php']);
    } finally {
        ServiceProvider::$publishGroups = $groups;
        ServiceProvider::$publishes = $providers;
    }
});

it('preserves a host canonical publish group instead of expanding it', function (): void {
    $groups = ServiceProvider::$publishGroups;
    $providers = ServiceProvider::$publishes;
    ServiceProvider::$publishGroups['nvl-media-config'] = ['/host/reserved.php' => '/host/config/reserved.php'];
    try {
        (new NamespacedPublicationProvider(app()))->boot();
        expect(ServiceProvider::pathsToPublish(null, 'nvl-media-config'))->toBe(['/host/reserved.php' => '/host/config/reserved.php']);
    } finally {
        ServiceProvider::$publishGroups = $groups;
        ServiceProvider::$publishes = $providers;
    }
});
