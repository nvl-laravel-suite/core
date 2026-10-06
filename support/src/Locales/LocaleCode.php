<?php

declare(strict_types=1);

namespace Nvl\Support\Locales;

use InvalidArgumentException;

/** Normalizes BCP-47-compatible locale identifiers without optional package dependencies. */
final readonly class LocaleCode
{
    public string $value;

    /**
     * Create a validated, normalized locale identifier.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(string $locale)
    {
        $normalized = self::normalize($locale);

        if (! self::isValid($normalized)) {
            throw new InvalidArgumentException("The locale [{$locale}] is not a valid locale code.");
        }

        $this->value = $normalized;
    }

    /** Normalize locale separators and subtag casing. */
    public static function normalize(string $locale): string
    {
        $segments = explode('-', str_replace('_', '-', trim($locale)));

        foreach ($segments as $index => $segment) {
            $segments[$index] = match (true) {
                $index === 0 => mb_strtolower($segment),
                mb_strlen($segment) === 2 || ctype_digit($segment) => mb_strtoupper($segment),
                mb_strlen($segment) === 4 => mb_strtoupper(mb_substr($segment, 0, 1)).mb_strtolower(mb_substr($segment, 1)),
                default => mb_strtolower($segment),
            };
        }

        return implode('-', $segments);
    }

    /** Determine whether a canonical locale has the supported storage shape. */
    public static function isValid(string $locale): bool
    {
        return mb_strlen($locale) <= 35
            && preg_match('/^[a-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/', $locale) === 1;
    }

    /** Return the canonical identifier. */
    public function __toString(): string
    {
        return $this->value;
    }
}
