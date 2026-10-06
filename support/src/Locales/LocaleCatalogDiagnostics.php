<?php

declare(strict_types=1);

namespace Nvl\Support\Locales;

use Exception;
use Illuminate\Config\Repository as Configuration;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Nvl\Support\Contracts\LocaleCatalog;

/** Reports legacy locale configuration and conflicts without changing stored content. */
final readonly class LocaleCatalogDiagnostics
{
    /** Create read-only locale compatibility diagnostics. */
    public function __construct(private Repository $config, private LocaleCatalog $catalog) {}

    /**
     * Inspect the selected catalog and deprecated Primitives configuration.
     *
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function inspect(): array
    {
        $errors = [];
        $warnings = [];

        try {
            $supported = $this->catalog->supported();
            $this->catalog->default();
            $this->catalog->fallbacks();
        } catch (Exception $exception) {
            $errors[] = $exception->getMessage();
            $supported = [];
        }

        $legacy = $this->config->get('nvl-primitives.locales.supported');

        if ($legacy !== null) {
            $warnings[] = 'nvl-primitives.locales is deprecated; bind LocaleCatalog or configure nvl-translatable.locales.';

            try {
                $legacyLocales = (new ApplicationLocaleCatalog(new Configuration([
                    'nvl-primitives' => ['locales' => ['supported' => $legacy]],
                ])))->supported();
                sort($legacyLocales);
                $selectedLocales = $supported;
                sort($selectedLocales);

                if ($legacyLocales !== $selectedLocales) {
                    $errors[] = 'nvl-primitives.locales.supported conflicts with the selected LocaleCatalog.';
                }
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        return ['errors' => array_values(array_unique($errors)), 'warnings' => $warnings];
    }
}
