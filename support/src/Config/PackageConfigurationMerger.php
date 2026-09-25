<?php

declare(strict_types=1);

namespace Nvl\Support\Config;

/**
 * Merges package defaults with an intentional host configuration overlay.
 */
final class PackageConfigurationMerger
{
    /**
     * Maps merge recursively. Lists and all type changes use the host value.
     *
     * @param  array<mixed>  $defaults
     * @param  array<mixed>  $host
     * @return array<mixed>
     */
    public static function merge(array $defaults, array $host): array
    {
        $merged = $defaults;

        foreach ($host as $key => $hostValue) {
            $defaultExists = array_key_exists($key, $defaults);
            $defaultValue = $defaultExists ? $defaults[$key] : null;

            if ($defaultExists
                && is_array($defaultValue)
                && is_array($hostValue)
                && ! array_is_list($defaultValue)
                && ! array_is_list($hostValue)) {
                $merged[$key] = self::merge($defaultValue, $hostValue);

                continue;
            }

            $merged[$key] = $hostValue;
        }

        return $merged;
    }
}
