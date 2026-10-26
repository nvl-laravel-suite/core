<?php

declare(strict_types=1);

namespace Nvl\Data\TypeScript;

use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Spatie\TypeScriptTransformer\Actions\ResolveImportsAndResolvedReferenceMapAction;
use Spatie\TypeScriptTransformer\Actions\SplitTransformedPerLocationAction;
use Spatie\TypeScriptTransformer\Collections\TransformedCollection;
use Spatie\TypeScriptTransformer\Data\GlobalNamespaceResolvedReference;
use Spatie\TypeScriptTransformer\Data\Location;
use Spatie\TypeScriptTransformer\Data\WriteableFile;
use Spatie\TypeScriptTransformer\Data\WritingContext;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNamespace;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptOperator;
use Spatie\TypeScriptTransformer\Writers\Writer;

/**
 * Writes ambient declarations into stable namespace scopes and one compatibility entrypoint.
 */
final class SplitNamespaceWriter implements Writer
{
    /**
     * @var list<array{prefix: string, scope: string}>
     */
    private readonly array $orderedScopeMappings;

    /**
     * Create a deterministic split declaration writer.
     *
     * @param  array<string, string>  $scopeMappings  Namespace-prefix to public-scope mappings
     */
    public function __construct(
        private readonly string $entrypointPath,
        private readonly string $scopeDirectory,
        array $scopeMappings = [],
        private readonly SplitTransformedPerLocationAction $splitTransformedPerLocationAction = new SplitTransformedPerLocationAction,
        private readonly ResolveImportsAndResolvedReferenceMapAction $resolveReferencesAction = new ResolveImportsAndResolvedReferenceMapAction,
    ) {
        $orderedMappings = [];

        foreach ($scopeMappings as $prefix => $scope) {
            $orderedMappings[] = [
                'prefix' => trim(str_replace('.', '\\', $prefix), '\\'),
                'scope' => $scope,
            ];
        }

        usort(
            $orderedMappings,
            static fn (array $left, array $right): int => [
                -strlen($left['prefix']),
                $left['prefix'],
            ] <=> [
                -strlen($right['prefix']),
                $right['prefix'],
            ],
        );

        $this->orderedScopeMappings = $orderedMappings;
    }

    /**
     * Write every transformed symbol into a deterministic scope file.
     *
     * @param  array<int, Transformed>  $transformed  Transformed symbols assigned to this writer
     * @return list<WriteableFile>
     */
    public function output(
        array $transformed,
        TransformedCollection $transformedCollection,
    ): array {
        $grouped = $this->groupByOutputPath($transformed);
        $entrypointPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $this->entrypointPath);

        foreach (array_keys($grouped) as $scopePath) {
            $scopePath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $scopePath);

            if ($scopePath === $entrypointPath) {
                throw new RuntimeException(
                    "Generated declaration entrypoint [{$this->entrypointPath}] collides with a scoped declaration.",
                );
            }
        }

        $files = [];

        foreach ($grouped as $path => $scopeTransformed) {
            $files[] = $this->writeScopeFile($path, $scopeTransformed, $transformedCollection);
        }

        array_unshift($files, $this->writeCompatibilityFile(array_keys($grouped)));

        return $files;
    }

    /**
     * Resolve a transformed reference as a fully qualified ambient namespace name.
     */
    public function resolveReference(Transformed $transformed): GlobalNamespaceResolvedReference
    {
        return new GlobalNamespaceResolvedReference(
            implode('.', [...$transformed->getLocation(), $transformed->getName()]),
        );
    }

    /**
     * Group transformed symbols by their configured output scope.
     *
     * @param  array<int, Transformed>  $transformed  Transformed symbols to group
     * @return array<string, array<int, Transformed>>
     */
    private function groupByOutputPath(array $transformed): array
    {
        $grouped = [];

        foreach ($transformed as $item) {
            $path = $this->outputPathFor($item);
            $grouped[$path] ??= [];
            $grouped[$path][] = $item;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * Resolve the declaration path for one transformed symbol.
     */
    private function outputPathFor(Transformed $transformed): string
    {
        $location = array_values($transformed->getLocation());
        $scope = $this->mappedScope($location)
            ?? $this->conventionalScope($location);

        return $this->scopeDirectory.DIRECTORY_SEPARATOR.$scope.'.d.ts';
    }

    /**
     * Resolve the most specific configured namespace-prefix mapping.
     *
     * @param  list<string>  $location  Namespace location segments
     */
    private function mappedScope(array $location): ?string
    {
        $namespace = implode('\\', $location);

        foreach ($this->orderedScopeMappings as $mapping) {
            $prefix = $mapping['prefix'];

            if ($namespace === $prefix || str_starts_with($namespace, $prefix.'\\')) {
                return $mapping['scope'];
            }
        }

        return null;
    }

    /**
     * Derive a stable scope from application, module, or NVL package namespaces.
     *
     * @param  list<string>  $location  Namespace location segments
     */
    private function conventionalScope(array $location): string
    {
        $segment = match ($location[0] ?? null) {
            'Modules', 'Nvl' => $location[1] ?? $location[0],
            null => 'global',
            default => $location[0],
        };
        $scope = Str::kebab($segment);

        if ($scope === '') {
            throw new InvalidArgumentException('A transformed type cannot be assigned to an empty scope.');
        }

        return $scope;
    }

    /**
     * Write one scoped ambient declaration file.
     *
     * @param  array<int, Transformed>  $transformed  Scope-local transformed symbols
     */
    private function writeScopeFile(
        string $path,
        array $transformed,
        TransformedCollection $transformedCollection,
    ): WriteableFile {
        $root = $this->splitTransformedPerLocationAction->execute($transformed);
        [$imports, $resolvedReferenceMap] = $this->resolveReferencesAction->execute(
            $path,
            $transformed,
            $transformedCollection,
        );
        $writingContext = new WritingContext($resolvedReferenceMap);
        $output = $this->header();
        $hasImports = count($imports->getTypeScriptNodes()) > 0;

        foreach ($imports->getTypeScriptNodes() as $import) {
            $output .= $import->write($writingContext).PHP_EOL;
        }

        if ($hasImports) {
            $output .= 'declare global {'.PHP_EOL;
        }

        foreach ($root->transformed as $transformable) {
            $output .= $transformable->write($writingContext).PHP_EOL;
        }

        foreach ($root->children as $child) {
            $namespace = $this->buildNamespace($child);
            $node = $hasImports ? $namespace : TypeScriptOperator::declare($namespace);

            $output .= $node->write($writingContext).PHP_EOL;
        }

        if ($hasImports) {
            $output .= '}'.PHP_EOL;
        }

        return new WriteableFile($path, $output);
    }

    /**
     * Build one TypeScript namespace tree recursively.
     */
    private function buildNamespace(Location $location): TypeScriptNamespace
    {
        $children = [];

        foreach ($location->children as $child) {
            $children[] = $this->buildNamespace($child);
        }

        return new TypeScriptNamespace(
            $location->name,
            $location->transformed,
            $children,
        );
    }

    /**
     * Write the compatibility entrypoint that references every scope file.
     *
     * @param  list<string>  $paths  Scoped declaration paths
     */
    private function writeCompatibilityFile(array $paths): WriteableFile
    {
        sort($paths);
        $output = $this->header();
        $entrypointDirectory = pathinfo($this->entrypointPath, PATHINFO_DIRNAME);

        foreach ($paths as $path) {
            $referencePath = $entrypointDirectory === '.'
                ? $path
                : $this->relativePath($entrypointDirectory, $path);
            $output .= '/// <reference path="./'
                .str_replace(DIRECTORY_SEPARATOR, '/', $referencePath)
                .'" />'
                .PHP_EOL;
        }

        return new WriteableFile($this->entrypointPath, $output);
    }

    /**
     * Resolve a relative path from the entrypoint directory to one scope path.
     */
    private function relativePath(string $fromDirectory, string $path): string
    {
        $from = array_values(array_filter(explode('/', str_replace('\\', '/', $fromDirectory))));
        $to = array_values(array_filter(explode('/', str_replace('\\', '/', $path))));

        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return implode('/', [
            ...array_fill(0, count($from), '..'),
            ...$to,
        ]);
    }

    /**
     * Return the standard generated declaration header.
     */
    private function header(): string
    {
        return '// This file is generated by php artisan nvl:data:types:generate.'.PHP_EOL
            .'// Do not edit this file manually.'.PHP_EOL.PHP_EOL;
    }
}
