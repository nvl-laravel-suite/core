<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Compatibility;

use Illuminate\Container\Container;

/** Selects the one existing queue authority during the supported bridge release. @internal */
final readonly class LegacyQueueAuthority
{
    /** Resolve configuration on each entry without retaining a second execution context. */
    public function __construct(private Container $container) {}

    /** Converted dual-contract commands use the legacy envelope only before cutover. */
    public function active(): bool
    {
        $config = $this->container->make('config');

        return $this->container->bound('nvl.tenancy.runtime')
            && $config->get('nvl-tenancy.enabled') === true
            && $config->get('nvl-ownership.enabled') !== true;
    }
}
