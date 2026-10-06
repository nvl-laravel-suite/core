<?php

declare(strict_types=1);

namespace Nvl\Support\Config;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/** Owns canonical NVL roots and explicitly selected legacy configuration reads. */
final class PackageConfiguration
{
    /** Return a canonical configuration root for a logical package or existing root. */
    public static function key(string $package): string
    {
        return str_starts_with($package, 'nvl-') ? $package : 'nvl-'.$package;
    }

    /** Return a logical package identifier without changing domain resource identifiers. */
    public static function logical(string $package): string
    {
        return str_starts_with($package, 'nvl-') ? substr($package, 4) : $package;
    }

    /**
     * Read selected legacy options validated against the package's known defaults.
     *
     * @param  array<string, mixed>  $defaults  Known canonical options
     * @return array<string, mixed> Validated legacy inputs
     */
    public static function legacy(Repository $config, string $package, array $defaults): array
    {
        $package = self::logical($package);
        $selected = $config->get('nvl-core.compatibility.legacy_config', []);
        if (! is_array($selected) || ! array_is_list($selected)) {
            throw new InvalidArgumentException('nvl-core.compatibility.legacy_config must be a package list.');
        }
        if (! in_array($package, $selected, true) || in_array($package, ['auth', 'core', 'data'], true)) {
            return [];
        }
        $legacy = $config->get($package, []);
        if (! is_array($legacy)) {
            throw new InvalidArgumentException("Legacy configuration [{$package}] must be a map.");
        }
        self::validate($legacy, PackageStorage::legacyDefaults($package, $defaults), $package);
        $config->set("nvl-core.configuration.legacy_reads.{$package}", self::key($package));

        return $legacy;
    }

    /**
     * Identify NVL-specific legacy shapes only during explicit diagnostics.
     *
     * @return array<string, string> Legacy package and canonical replacement
     */
    public static function detectedLegacy(Repository $config): array
    {
        $fingerprints = [
            'activity' => ['capture' => 'array', 'causer_suggestions' => 'array'],
            'billing' => ['access.plans' => 'array', 'subscription_type' => 'string'],
            'comments' => ['rich_text' => 'array', 'idempotency' => 'array', 'mentions' => 'array'],
            'content' => ['definition_paths' => 'array', 'field_types' => 'array', 'placements' => 'array'],
            'forms' => ['submission.max_payload_bytes' => 'integer', 'submission.max_depth' => 'integer'],
            'mail-notifications' => ['notifiable_types' => 'array', 'tracking.excluded_mailers' => 'array'],
            'media' => ['owner_slots.idempotency' => 'array', 'deduplication_lock' => 'array'],
            'metafields' => ['reference_models' => 'array', 'limits.maximum_schema_properties' => 'integer'],
            'pages' => ['hierarchy.maximum_depth' => 'integer', 'urls.generator' => 'string'],
            'payments' => ['reconciliation.max_attempts' => 'integer', 'checkout.expires_in_minutes' => 'integer', 'allowed_currencies' => 'array'],
            'primitives' => ['exchange_rates.implementation' => 'string'],
            'seo' => ['sitemap.sources' => 'array', 'structured_data' => 'array'],
            'settings' => ['discovery.paths' => 'array', 'overrides' => 'array'],
            'tasks' => ['activity.drain_limit' => 'integer', 'media.maximum_attachments' => 'integer'],
            'taxonomy' => ['taxonomies.tag.model' => 'string', 'limits.metadata_bytes' => 'integer'],
            'templates' => ['rendering.lease_seconds' => 'integer', 'rendering.pending_recovery_seconds' => 'integer'],
            'tenancy' => ['sharing.media' => 'string', 'sharing.metafields' => 'string', 'directory.driver' => 'string'],
            'translatable' => ['fallback.policy' => 'string', 'limits.mutation_locales' => 'integer'],
            'translations' => ['export_targets' => 'array', 'scan_allowlist' => 'array'],
        ];
        $detected = [];
        foreach ($fingerprints as $package => $paths) {
            if ($config->get("nvl-core.configuration.canonical_supplied.{$package}", false) === true
                || $config->get("nvl-core.configuration.legacy_reads.{$package}") !== null) {
                continue;
            }
            $legacy = $config->get($package);
            if (! is_array($legacy)) {
                continue;
            }
            $matches = true;
            foreach ($paths as $path => $type) {
                if (! Arr::has($legacy, $path) || gettype(Arr::get($legacy, $path)) !== $type) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                $detected[$package] = self::key($package);
            }
        }

        return $detected;
    }

    /**
     * Reject unknown paths and incompatible types while allowing declared open maps.
     *
     * @param  array<mixed>  $legacy  Legacy options
     * @param  array<mixed>  $defaults  Known option shapes
     *
     * @phpstan-assert array<string, mixed> $legacy
     */
    private static function validate(array $legacy, array $defaults, string $path): void
    {
        foreach ($legacy as $key => $value) {
            if (! is_string($key) || ! array_key_exists($key, $defaults)) {
                throw new InvalidArgumentException("Unknown legacy NVL option [{$path}.{$key}].");
            }
            $default = $defaults[$key];
            if ($default !== null && $value !== null && gettype($default) !== gettype($value)) {
                throw new InvalidArgumentException("Legacy NVL option [{$path}.{$key}] has an incompatible type.");
            }
            if (is_array($default) && is_array($value) && $default !== [] && ! array_is_list($default)) {
                self::validate($value, $default, "{$path}.{$key}");
            }
        }
    }

    private function __construct() {}
}
