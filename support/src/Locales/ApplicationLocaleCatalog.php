<?php

declare(strict_types=1);

namespace Nvl\Support\Locales;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Nvl\Support\Contracts\LocaleCatalog;

/** Supplies content locale defaults from application configuration in standalone Core installs. */
final readonly class ApplicationLocaleCatalog implements LocaleCatalog
{
    /** Create the application-backed catalog with optional legacy configuration support. */
    public function __construct(private Repository $config, private bool $allowLegacyCatalog = true) {}

    /**
     * Return distinct valid application locales or the transitional legacy catalog.
     *
     * @return list<string>
     */
    public function supported(): array
    {
        $canonical = $this->config->get('nvl-core.locales.supported');
        $legacy = $this->config->get('primitives.locales.supported');

        if ($canonical !== null) {
            return $this->normalizeList($canonical, 'nvl-core.locales.supported');
        }

        if ($this->allowLegacyCatalog && $legacy !== null) {
            return $this->normalizeList($legacy, 'primitives.locales.supported');
        }

        $locales = $this->applicationLocales();

        if ($locales === []) {
            throw new InvalidArgumentException('app.locale or app.fallback_locale must contain a valid locale.');
        }

        return $locales;
    }

    /** Return the first supported application locale or the first legacy locale. */
    public function default(): string
    {
        $configured = $this->config->get('nvl-core.locales.default');

        if ($configured !== null) {
            if (! is_string($configured)) {
                throw new InvalidArgumentException('nvl-core.locales.default must be a string or null.');
            }

            return $this->assertSupported($configured);
        }

        $supported = $this->supported();

        foreach ($this->applicationLocales() as $locale) {
            if (in_array($locale, $supported, true)) {
                return $locale;
            }
        }

        return $supported[0];
    }

    /**
     * Return the valid supported application fallback locale.
     *
     * @return list<string>
     */
    public function fallbacks(): array
    {
        $configured = $this->config->get('nvl-core.locales.fallback');

        if ($configured !== null) {
            return array_map(
                $this->assertSupported(...),
                $this->normalizeList($configured, 'nvl-core.locales.fallback', allowEmpty: true),
            );
        }

        $locale = $this->config->get('app.fallback_locale');

        return is_string($locale) && $this->supports($locale) ? [$this->normalize($locale)] : [];
    }

    /** Normalize and validate a locale identifier. */
    public function normalize(string $locale): string
    {
        return (new LocaleCode($locale))->value;
    }

    /** Determine whether a valid locale belongs to the catalog. */
    public function supports(string $locale): bool
    {
        if (! LocaleCode::isValid(LocaleCode::normalize($locale))) {
            return false;
        }

        return in_array($this->normalize($locale), $this->supported(), true);
    }

    /** Require catalog membership for a normalized locale. */
    public function assertSupported(string $locale): string
    {
        $normalized = $this->normalize($locale);

        if (! in_array($normalized, $this->supported(), true)) {
            throw new InvalidArgumentException("The locale [{$normalized}] is not supported.");
        }

        return $normalized;
    }

    /**
     * Return requested locale, supported parents, explicit fallbacks, and application defaults.
     *
     * @param  list<mixed>  $additionalFallbacks
     * @return list<string>
     */
    public function chain(string $requestedLocale, array $additionalFallbacks = []): array
    {
        $requested = $this->assertSupported($requestedLocale);
        $candidates = [$requested];
        $segments = explode('-', $requested);

        while (count($segments) > 1) {
            array_pop($segments);
            $parent = implode('-', $segments);

            if ($this->supports($parent)) {
                $candidates[] = $parent;
            }
        }

        foreach ([...$additionalFallbacks, ...$this->fallbacks(), $this->default()] as $locale) {
            if (! is_string($locale)) {
                throw new InvalidArgumentException('Every additional fallback locale must be a string.');
            }

            $candidates[] = $this->assertSupported($locale);
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Return distinct valid locales from the application configuration.
     *
     * @return list<string>
     */
    private function applicationLocales(): array
    {
        $locales = [];

        foreach (['app.locale', 'app.fallback_locale'] as $key) {
            $locale = $this->config->get($key);

            if (is_string($locale) && LocaleCode::isValid(LocaleCode::normalize($locale))) {
                $locales[] = $this->normalize($locale);
            }
        }

        return array_values(array_unique($locales));
    }

    /**
     * Normalize a configured locale list and reject ambiguous values.
     *
     * @return list<string>
     */
    private function normalizeList(mixed $configured, string $path, bool $allowEmpty = false): array
    {
        if (! is_array($configured) || (! $allowEmpty && $configured === [])) {
            throw new InvalidArgumentException("{$path} must be an array".($allowEmpty ? '.' : ' containing at least one locale.'));
        }

        $locales = [];

        foreach ($configured as $locale) {
            if (! is_string($locale)) {
                throw new InvalidArgumentException("Every {$path} value must be a string.");
            }

            $normalized = $this->normalize($locale);

            if (in_array($normalized, $locales, true)) {
                throw new InvalidArgumentException("Duplicate normalized locale [{$normalized}] in {$path}.");
            }

            $locales[] = $normalized;
        }

        return $locales;
    }
}
