<?php

declare(strict_types=1);

namespace Nvl\Data\Services;

use Illuminate\Contracts\Config\Repository;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Spatie\LaravelData\Contracts\BaseData;
use Spatie\TypeScriptTransformer\Actions\DiscoverTypesAction;
use Spatie\TypeScriptTransformer\Attributes\Hidden;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\Transformers\EnumProviders\PhpEnumProvider;
use SplFileInfo;

/**
 * Mirrors generated PHP type selection for deterministic public symbol manifests.
 */
final readonly class TypeScriptSourceInspector
{
    /**
     * Create a TypeScript source inspector.
     */
    public function __construct(
        private Repository $config,
        private TypeScriptSourceRegistry $sources,
        private DiscoverTypesAction $discoverTypes,
        private PhpEnumProvider $enumProvider,
    ) {}

    /**
     * Return every generated source type and its stable public TypeScript symbol.
     *
     * @return list<array{phpType: string, typescriptType: string, package: string|null, source: string}>
     */
    public function symbols(): array
    {
        $symbols = [];
        $seenTypes = [];

        foreach ($this->discoverTypes->execute($this->sources->all()) as $phpType) {
            $attributes = $phpType->getAttributes(TypeScript::class);

            if (! $this->isGeneratedType($phpType, $attributes !== [])) {
                continue;
            }

            $source = $this->sourceFor($phpType);
            $relativePath = ltrim(
                substr($source['file'], strlen($source['descriptor']['path'])),
                DIRECTORY_SEPARATOR,
            );
            $arguments = $attributes === []
                ? []
                : array_values($attributes)[0]->getRawArguments();
            $typescriptType = $this->typescriptType($phpType, $arguments);

            if (isset($seenTypes[$typescriptType])) {
                throw new RuntimeException(
                    "TypeScript symbol [{$typescriptType}] is declared by both "
                    ."[{$seenTypes[$typescriptType]}] and [{$relativePath}].",
                );
            }

            $seenTypes[$typescriptType] = $relativePath;
            $symbols[] = [
                'phpType' => $phpType->getName(),
                'typescriptType' => $typescriptType,
                'package' => $source['descriptor']['package'],
                'source' => str_replace(DIRECTORY_SEPARATOR, '/', $relativePath),
            ];
        }

        usort(
            $symbols,
            static fn (array $left, array $right): int => [
                $left['typescriptType'],
                $left['source'],
            ] <=> [
                $right['typescriptType'],
                $right['source'],
            ],
        );

        return $symbols;
    }

    /**
     * Mirror the configured transformer selection rules without transforming properties again.
     */
    private function isGeneratedType(PhpClassNode $phpType, bool $hasTypeScriptAttribute): bool
    {
        if ($phpType->getAttributes(Hidden::class) !== []) {
            return false;
        }

        if (! $phpType->isEnum() && ! $phpType->isInterface()) {
            return $phpType->implementsInterface(BaseData::class)
                || $hasTypeScriptAttribute;
        }

        if (! $this->enumProvider->isEnum($phpType)) {
            return false;
        }

        $useUnionEnums = $this->config->get(
            'nvl-data.typescript.enum_union_types',
            true,
        );

        if (! is_bool($useUnionEnums)) {
            throw new RuntimeException(
                'Configuration [nvl-data.typescript.enum_union_types] must be a boolean.',
            );
        }

        if ($useUnionEnums && ! $this->enumProvider->isValidUnion($phpType)) {
            return false;
        }

        return $this->enumProvider->resolveCases($phpType) !== [];
    }

    /**
     * Fail before transformation when source discovery exceeds configured bounds.
     */
    public function assertWithinLimits(): void
    {
        $this->assertSourceFilesWithinLimits();
    }

    /**
     * Resolve the exact generated symbol, including TypeScript attribute overrides.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    private function typescriptType(PhpClassNode $phpType, array $arguments): string
    {
        $name = $arguments['name'] ?? $arguments[0] ?? $phpType->getShortName();
        $location = $arguments['location']
            ?? $arguments[1]
            ?? ($phpType->inNamespace() ? explode('\\', $phpType->getNamespaceName()) : []);

        if (
            ! is_string($name)
            || $name === ''
            || ! is_array($location)
            || array_filter($location, static fn (mixed $segment): bool => ! is_string($segment) || $segment === '') !== []
        ) {
            throw new RuntimeException(
                "TypeScript attribute arguments for [{$phpType->getName()}] are invalid.",
            );
        }

        /** @var list<string> $location */
        return implode('.', [...$location, $name]);
    }

    /**
     * Resolve source ownership for one discovered PHP type.
     *
     * @return array{
     *     file: string,
     *     descriptor: array{path: string, package: string|null, priority: int}
     * }
     */
    private function sourceFor(PhpClassNode $phpType): array
    {
        $file = realpath($phpType->getFileName());

        if ($file === false) {
            throw new RuntimeException("Unable to resolve the source for [{$phpType->getName()}].");
        }

        foreach ($this->sources->descriptors() as $descriptor) {
            if ($file === $descriptor['path'] || str_starts_with($file, $descriptor['path'].DIRECTORY_SEPARATOR)) {
                return [
                    'file' => $file,
                    'descriptor' => $descriptor,
                ];
            }
        }

        throw new RuntimeException("Discovered TypeScript source [{$file}] has no registered owner.");
    }

    /**
     * Count unique source files without retaining a second in-memory source catalog.
     */
    private function assertSourceFilesWithinLimits(): void
    {
        $maximumFiles = $this->config->get('nvl-data.typescript.max_source_files', 50_000);

        if (! is_int($maximumFiles) || $maximumFiles < 1) {
            throw new RuntimeException('nvl-data.typescript.max_source_files must be a positive integer.');
        }

        $sourceFileCount = 0;
        $seenFiles = [];

        foreach ($this->sources->descriptors() as $source) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $source['path'],
                    RecursiveDirectoryIterator::SKIP_DOTS,
                ),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $resolvedPath = realpath($file->getPathname());

                if ($resolvedPath === false) {
                    throw new RuntimeException(
                        "TypeScript source file [{$file->getPathname()}] cannot be resolved.",
                    );
                }

                if (! str_starts_with($resolvedPath, $source['path'].DIRECTORY_SEPARATOR)) {
                    throw new RuntimeException(
                        'TypeScript source symlinks cannot leave their registered directory.',
                    );
                }

                if (isset($seenFiles[$resolvedPath])) {
                    continue;
                }

                $seenFiles[$resolvedPath] = true;
                $sourceFileCount++;

                if ($sourceFileCount > $maximumFiles) {
                    throw new RuntimeException('TypeScript source discovery exceeds the configured file-count limit.');
                }
            }
        }
    }
}
