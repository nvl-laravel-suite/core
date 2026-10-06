<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Services;

use Closure;
use Illuminate\Http\Request;
use Nvl\Support\Tenancy\ValueObjects\TenantSiteContext;

/** Preserves the public site request attribute contract during the neutral namespace transition. */
final class TenantSiteAttributes
{
    public const string LegacyKey = 'Nvl\\Tenancy\\ValueObjects\\TenantSiteContext';

    /** Keep explicit canonical values authoritative, including invalid values that readers reject. */
    public static function read(Request $request): mixed
    {
        return $request->attributes->has(TenantSiteContext::class)
            ? $request->attributes->get(TenantSiteContext::class)
            : $request->attributes->get(self::LegacyKey);
    }

    /**
     * Publish both request keys and return a callback restoring their independent prior states.
     *
     * @return Closure(): void
     */
    public static function store(Request $request, TenantSiteContext $site): Closure
    {
        $previous = [];
        foreach ([TenantSiteContext::class, self::LegacyKey] as $key) {
            $previous[$key] = [
                'present' => $request->attributes->has($key),
                'value' => $request->attributes->get($key),
            ];
            $request->attributes->set($key, $site);
        }

        return static function () use ($request, $previous): void {
            foreach ($previous as $key => $state) {
                if ($state['present']) {
                    $request->attributes->set($key, $state['value']);
                } else {
                    $request->attributes->remove($key);
                }
            }
        };
    }

    private function __construct() {}
}
