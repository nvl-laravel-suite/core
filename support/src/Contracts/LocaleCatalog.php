<?php

declare(strict_types=1);

namespace Nvl\Support\Contracts;

/**
 * Defines the content locale catalog shared by independently installed capabilities.
 */
interface LocaleCatalog
{
    /**
     * Return the normalized supported content locales.
     *
     * @return list<string>
     */
    public function supported(): array;

    /** Return the default content locale. */
    public function default(): string;

    /**
     * Return the configured fallback locales in order.
     *
     * @return list<string>
     */
    public function fallbacks(): array;

    /** Normalize and validate a locale identifier. */
    public function normalize(string $locale): string;

    /** Determine whether a locale is supported. */
    public function supports(string $locale): bool;

    /** Normalize a locale and require catalog membership. */
    public function assertSupported(string $locale): string;

    /**
     * Return a deterministic chain of supported requested, parent, and fallback locales.
     *
     * @param  list<mixed>  $additionalFallbacks
     * @return list<string>
     */
    public function chain(string $requestedLocale, array $additionalFallbacks = []): array;
}
