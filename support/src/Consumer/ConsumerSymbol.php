<?php

declare(strict_types=1);

namespace Nvl\Support\Consumer;

/**
 * Describes the supported members of one installed package symbol.
 *
 * @api
 */
final readonly class ConsumerSymbol
{
    /**
     * Retain the declaration and its verified installed source identity.
     *
     * @param  list<string>  $methods
     * @param  list<string>  $properties
     * @param  list<string>  $constants
     */
    public function __construct(
        public string $package,
        public string $class,
        public string $kind,
        public string $file,
        public array $methods,
        public array $properties,
        public array $constants,
        private string $canonicalSourcePath,
        public ?string $aliasOf = null,
    ) {}

    /**
     * Return the verified source path for infrastructure provenance checks.
     *
     * @internal
     */
    public function sourcePath(): string
    {
        return $this->canonicalSourcePath;
    }
}
