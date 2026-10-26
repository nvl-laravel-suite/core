<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

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
