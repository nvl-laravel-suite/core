<?php

declare(strict_types=1);

namespace Nvl\Data\Services;

use DateTime;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Nvl\Data\TypeScript\LaravelDataClassTransformer;
use Nvl\Data\TypeScript\SplitNamespaceWriter;
use RuntimeException;
use Spatie\LaravelTypeScriptTransformer\LaravelData\LaravelDataTypeScriptTransformerExtension;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAny;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptRaw;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnknown;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Visitor\VisitorOperation;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;

/**
 * Builds the default Spatie TypeScript Transformer configuration for NVL Data.
 */
final readonly class TypeScriptConfigurator
{
    /**
     * Create the transformer configurator.
     */
    public function __construct(
        private Repository $config,
        private Filesystem $files,
        private TypeScriptSourceRegistry $sources,
        private TypeScriptPathGuard $pathGuard,
        private TypeScriptSourceInspector $sourceInspector,
        private GeneratedArtifactSet $artifacts,
    ) {}

    /**
     * Apply configured discovery, transforms, and output settings.
     */
    public function configure(
        TypeScriptTransformerConfigFactory $factory,
        ?string $outputDirectory = null,
    ): void {
        $this->ensureMemoryLimit();
        $this->sourceInspector->assertWithinLimits();

        $resolvedOutputDirectory = $this->outputDirectory($outputDirectory);
        $this->files->ensureDirectoryExists($resolvedOutputDirectory);

        $sources = $this->sources->all();

        if ($sources === []) {
            throw new RuntimeException('NVL Data has no existing TypeScript source paths to transform.');
        }

        $useUnionEnums = $this->booleanConfiguration(
            'nvl-data.typescript.enum_union_types',
            true,
        );
        $preserveReadonlyProperties = $this->booleanConfiguration(
            'nvl-data.typescript.readonly_properties',
            false,
        );
        $modelType = $this->modelType();

        $factory
            ->transformer(AttributedClassTransformer::class)
            ->transformer(new EnumTransformer(
                useUnionEnums: $useUnionEnums,
            ))
            ->extension(new LaravelDataTypeScriptTransformerExtension)
            ->prependTransformer(new LaravelDataClassTransformer)
            ->transformDirectories(...$sources)
            ->replaceType(DateTime::class, 'string')
            ->replaceType(DateTimeImmutable::class, 'string')
            ->replaceType('Carbon\\CarbonInterface', 'string')
            ->replaceType('Carbon\\CarbonImmutable', 'string')
            ->replaceType('Carbon\\Carbon', 'string')
            ->replaceType(Carbon::class, 'string')
            ->providedVisitorHook(
                static function (TypeScriptReference $reference) use ($modelType): VisitorOperation {
                    if (! $reference->reference instanceof ClassStringReference) {
                        return VisitorOperation::keep();
                    }

                    if (! is_a($reference->reference->classString, Model::class, true)) {
                        return VisitorOperation::keep();
                    }

                    return VisitorOperation::replace($modelType);
                },
                [TypeScriptReference::class],
            )
            ->providedVisitorHook(
                static function (TypeScriptProperty $property) use ($preserveReadonlyProperties): VisitorOperation {
                    if (! $preserveReadonlyProperties) {
                        $property->isReadonly = false;
                    }

                    return VisitorOperation::keep();
                },
                [TypeScriptProperty::class],
            )
            ->outputDirectory($resolvedOutputDirectory)
            ->writer($this->writer());

        foreach ($this->typeReplacements() as $phpType => $typeScriptType) {
            $factory->replaceType($phpType, new TypeScriptRaw($typeScriptType));
        }
    }

    /**
     * Build an isolated transformer configuration for a generated-types check.
     */
    public function isolatedConfiguration(string $outputDirectory): TypeScriptTransformerConfig
    {
        $factory = (new TypeScriptTransformerConfigFactory)
            ->configPath(__FILE__);

        $this->configure($factory, $outputDirectory);

        return $factory->get();
    }

    /**
     * Return a validated absolute output directory.
     */
    private function outputDirectory(?string $override = null): string
    {
        $path = $override ?? $this->config->get('nvl-data.typescript.output_directory');

        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException('nvl-data.typescript.output_directory must be a non-empty path.');
        }

        return $this->pathGuard->outputDirectory($path);
    }

    /**
     * Return the relative declaration entrypoint filename.
     */
    private function outputFile(): string
    {
        $path = $this->config->get('nvl-data.typescript.output_file', 'generated.d.ts');

        if (! is_string($path)) {
            throw new RuntimeException('nvl-data.typescript.output_file must be a safe .d.ts path.');
        }

        return $this->artifacts->normalizeDeclarationPath($path);
    }

    /**
     * Build the configured declaration writer.
     */
    private function writer(): GlobalNamespaceWriter|SplitNamespaceWriter
    {
        $writer = $this->config->get('nvl-data.typescript.writer', 'split');

        return match ($writer) {
            'global' => new GlobalNamespaceWriter($this->outputFile()),
            'split' => new SplitNamespaceWriter(
                entrypointPath: $this->outputFile(),
                scopeDirectory: $this->splitDirectory(),
                scopeMappings: $this->scopeMappings(),
            ),
            default => throw new RuntimeException('nvl-data.typescript.writer must be [global] or [split].'),
        };
    }

    /**
     * Return the safe relative directory used for split declarations.
     */
    private function splitDirectory(): string
    {
        $directory = $this->config->get('nvl-data.typescript.split_directory', 'generated');
        $normalized = is_string($directory)
            ? str_replace('\\', '/', $directory)
            : null;
        $segments = is_string($normalized) ? explode('/', $normalized) : [];

        if (
            ! is_string($normalized)
            || $normalized === ''
            || $normalized !== trim($normalized)
            || preg_match('/^[A-Za-z0-9._\\/-]+$/', $normalized) !== 1
            || str_starts_with($normalized, '/')
            || array_filter(
                $segments,
                static fn (string $segment): bool => $segment === '' || $segment === '.' || $segment === '..',
            ) !== []
        ) {
            throw new RuntimeException('nvl-data.typescript.split_directory must be a safe relative path.');
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    }

    /**
     * Return validated namespace-prefix to scope mappings.
     *
     * @return array<string, string>
     */
    private function scopeMappings(): array
    {
        $configured = $this->config->get('nvl-data.typescript.scope_mappings', []);

        if (! is_array($configured)) {
            throw new RuntimeException('nvl-data.typescript.scope_mappings must be an array.');
        }

        $mappings = [];

        foreach ($configured as $namespace => $scope) {
            $normalizedNamespace = is_string($namespace)
                ? trim(str_replace('.', '\\', $namespace), '\\')
                : null;

            if (
                ! is_string($normalizedNamespace)
                || preg_match(
                    '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/',
                    $normalizedNamespace,
                ) !== 1
                || ! is_string($scope)
                || preg_match('/^[A-Za-z0-9_-]+$/', $scope) !== 1
            ) {
                throw new RuntimeException('Every TypeScript scope mapping must contain a namespace and route-safe scope.');
            }

            if (isset($mappings[$normalizedNamespace])) {
                throw new RuntimeException(
                    "TypeScript scope mapping [{$normalizedNamespace}] is configured more than once.",
                );
            }

            $mappings[$normalizedNamespace] = $scope;
        }

        return $mappings;
    }

    /**
     * Return the configured TypeScript replacement for Eloquent model references.
     */
    private function modelType(): TypeScriptAny|TypeScriptUnknown
    {
        return match ($this->config->get('nvl-data.typescript.model_type', 'any')) {
            'any' => new TypeScriptAny,
            'unknown' => new TypeScriptUnknown,
            default => throw new RuntimeException('nvl-data.typescript.model_type must be [any] or [unknown].'),
        };
    }

    /**
     * Return validated host and NVL TypeScript type replacements.
     *
     * @return array<string, string>
     */
    private function typeReplacements(): array
    {
        $maps = [
            'typescript-transformer.default_type_replacements',
            'typescript-transformer.default_type_relacements',
            'typescript-transformer.type_replacements',
            'nvl-data.typescript.type_replacements',
        ];
        $replacements = [];

        foreach ($maps as $key) {
            $configured = $this->config->get($key, []);

            if (! is_array($configured)) {
                throw new RuntimeException("Configuration [{$key}] must be a PHP-to-TypeScript replacement map.");
            }

            foreach ($configured as $phpType => $typeScriptType) {
                if (! is_string($phpType)
                    || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $phpType) !== 1
                    || ! is_string($typeScriptType)
                    || trim($typeScriptType) === '') {
                    throw new RuntimeException(
                        "Every replacement in [{$key}] must map a PHP type to a non-empty TypeScript type.",
                    );
                }

                $replacements[$phpType] = trim($typeScriptType);
            }
        }

        return $replacements;
    }

    /**
     * Resolve one strictly typed boolean transformer setting.
     */
    private function booleanConfiguration(string $key, bool $default): bool
    {
        $value = $this->config->get($key, $default);

        if (! is_bool($value)) {
            throw new RuntimeException("Configuration [{$key}] must be a boolean.");
        }

        return $value;
    }

    /**
     * Raise the transform memory limit without lowering unlimited or larger limits.
     */
    private function ensureMemoryLimit(): void
    {
        $configuredLimit = $this->config->get('nvl-data.typescript.memory_limit', '1G');

        if (! is_string($configuredLimit) || trim($configuredLimit) === '') {
            throw new RuntimeException('nvl-data.typescript.memory_limit must be a non-empty PHP memory limit.');
        }

        $currentBytes = $this->memoryLimitBytes((string) ini_get('memory_limit'));
        $configuredBytes = $this->memoryLimitBytes($configuredLimit);

        if ($configuredBytes === null || $currentBytes === null || $currentBytes >= $configuredBytes) {
            return;
        }

        if (ini_set('memory_limit', $configuredLimit) === false) {
            throw new RuntimeException("Unable to raise the PHP memory limit to [{$configuredLimit}].");
        }
    }

    /**
     * Convert one PHP memory-limit value into bytes, with null representing unlimited.
     */
    private function memoryLimitBytes(string $value): ?int
    {
        $trimmed = trim($value);

        if ($trimmed === '-1') {
            return null;
        }

        if (preg_match('/^([0-9]+)\\s*([KMG]?)$/i', $trimmed, $matches) !== 1) {
            throw new RuntimeException("Invalid PHP memory limit [{$value}].");
        }

        $digits = ltrim($matches[1], '0');
        $digits = $digits === '' ? '0' : $digits;
        $maximumInteger = (string) PHP_INT_MAX;

        if (
            strlen($digits) > strlen($maximumInteger)
            || (strlen($digits) === strlen($maximumInteger) && strcmp($digits, $maximumInteger) > 0)
        ) {
            throw new RuntimeException("PHP memory limit [{$value}] exceeds the supported integer range.");
        }

        $amount = (int) $digits;
        $multiplier = match (strtolower($matches[2])) {
            'g' => 1024 * 1024 * 1024,
            'm' => 1024 * 1024,
            'k' => 1024,
            default => 1,
        };

        if ($amount > intdiv(PHP_INT_MAX, $multiplier)) {
            throw new RuntimeException("PHP memory limit [{$value}] exceeds the supported integer range.");
        }

        return $amount * $multiplier;
    }
}
