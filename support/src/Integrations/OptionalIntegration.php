<?php

declare(strict_types=1);

namespace Nvl\Support\Integrations;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

/** Resolves optional capabilities from explicit switches and loaded providers. */
final readonly class OptionalIntegration
{
    /** Create the runtime provider availability reader. */
    public function __construct(private Repository $config, private Application $app) {}

    /** Determine whether a loaded adapter is selected for the capability. */
    public function enabled(string $key, string $provider, bool $requested = false): bool
    {
        $flag = $this->config->get($key);

        if ($flag !== null && ! is_bool($flag)) {
            throw new InvalidArgumentException("{$key} must be true, false, or null.");
        }

        if ($flag === false) {
            if ($requested) {
                throw new InvalidArgumentException("{$key} is disabled but its optional capability was explicitly requested.");
            }

            return false;
        }

        $loaded = ($this->app->getLoadedProviders()[$provider] ?? false) === true;

        if (! $loaded && ($flag === true || $requested)) {
            throw new InvalidArgumentException("{$key} requires the loaded provider [{$provider}].");
        }

        return $loaded;
    }

    /**
     * Describe optional availability without failing automatic absent adapters.
     *
     * @return array{key: string, severity: string, passed: bool, message: string}
     */
    public function check(string $key, string $provider, bool $requested = false): array
    {
        try {
            $enabled = $this->enabled($key, $provider, $requested);

            return [
                'key' => $key,
                'severity' => 'info',
                'passed' => true,
                'message' => $enabled ? 'The optional adapter is active.' : 'The optional adapter is inactive; load its provider to enable the capability.',
            ];
        } catch (InvalidArgumentException $exception) {
            return ['key' => $key, 'severity' => 'error', 'passed' => false, 'message' => $exception->getMessage()];
        }
    }
}
