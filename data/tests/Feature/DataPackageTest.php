<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\LockableFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Nvl\Data\Data\PaginatedCollection;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Data\Providers\DataTypeScriptTransformerServiceProvider;
use Nvl\Data\Services\GeneratedArtifactSet;
use Nvl\Data\Services\GeneratedTypeFileCatalog;
use Nvl\Data\Services\GeneratedTypesLock;
use Nvl\Data\Services\GeneratedTypesManifestWriter;
use Nvl\Data\Services\GeneratedTypesPublisher;
use Nvl\Data\Services\GeneratedTypesRouteConfiguration;
use Nvl\Data\Services\TypeScriptConfigurator;
use Nvl\Data\Services\TypeScriptPathGuard;
use Nvl\Data\Services\TypeScriptSourceInspector;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Data\Tests\Fixtures\DataPackageStatus;
use Nvl\Data\Tests\Fixtures\DataTransformFixture;
use Nvl\Data\Traits\DataTransform;
use Nvl\Data\TypeScript\SplitNamespaceWriter;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Collections\TransformedCollection;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;

beforeEach(function (): void {
    config(['nvl-data.typescript.adopt_global_config' => true]);
    app()->register(DataTypeScriptTransformerServiceProvider::class);
    $this->generatedTypesDirectory = storage_path('framework/testing/nvl-data-'.str()->uuid());
    File::ensureDirectoryExists($this->generatedTypesDirectory.'/generated');

    config()->set('nvl-data.typescript.output_directory', $this->generatedTypesDirectory);
    config()->set('nvl-data.typescript.output_file', 'generated.types.d.ts');
});

afterEach(function (): void {
    File::deleteDirectory($this->generatedTypesDirectory);
    app()->forgetInstance(GeneratedTypeFileCatalog::class);
});

test('it explicitly adopts a standalone transformer configuration and configurable source registry', function (): void {
    $registry = app(TypeScriptSourceRegistry::class);
    $registry->register(__DIR__.'/../Fixtures');

    expect(app()->bound(TypeScriptTransformerConfig::class))->toBeTrue()
        ->and($registry->all())->toContain(realpath(__DIR__.'/../Fixtures'))
        ->and($registry->all())->toContain(realpath(__DIR__.'/../../src'))
        ->and($registry->all())->not->toContain(false);
});

test('its package guidance targets installed transformer v3 attributes', function (): void {
    expect(class_exists(LiteralTypeScriptType::class))->toBeTrue()
        ->and(class_exists('Spatie\TypeScriptTransformer\Attributes\RecordTypeScriptType'))->toBeFalse();
});

test('its distributable configuration keeps generated type routes opt in and protected', function (): void {
    /** @var array{typescript: array{routes: array{enabled: bool, middleware: list<string>, cache_control: string}}} $defaults */
    $defaults = require __DIR__.'/../../config/nvl-data.php';

    expect($defaults['typescript']['routes']['enabled'])->toBeFalse()
        ->and($defaults['typescript']['routes']['middleware'])->toContain('auth')
        ->and($defaults['typescript']['routes']['cache_control'])->toBe('private, no-store');
});

test('it publishes both Core components through namespaced configuration groups', function (): void {
    $expectedTarget = config_path('nvl-data.php');

    expect(array_values(DataServiceProvider::pathsToPublish(
        DataServiceProvider::class,
        'nvl-data-config',
    )))->toContain($expectedTarget)
        ->and(array_values(DataServiceProvider::pathsToPublish(
            DataServiceProvider::class,
            'nvl-core-config',
        )))->toContain($expectedTarget)
        ->and(array_values(DataServiceProvider::pathsToPublish(null, 'config')))->not->toContain($expectedTarget);
});

test('it exposes versioned strict warning flags and generated tooling fragments', function (): void {
    $commands = Artisan::all();
    $generateOption = $commands['nvl:data:types:generate']
        ->getDefinition()
        ->getOption('fail-on-warning');
    $checkOption = $commands['nvl:data:types:check']
        ->getDefinition()
        ->getOption('fail-on-warning');
    $published = DataServiceProvider::pathsToPublish(
        DataServiceProvider::class,
        'nvl-data-generated-types-tooling',
    );

    expect($generateOption->getDescription())->toContain('1.0.2')
        ->and($checkOption->getDescription())->toContain('1.0.2')
        ->and(array_values($published))->toContain(
            base_path('nvl-data.eslint.config.js'),
            base_path('.nvl-data.prettierignore'),
        )
        ->and(File::get(__DIR__.'/../../resources/tooling/eslint.config.fragment.js'))
        ->toContain('resources/js/types/generated/**')
        ->and(File::get(__DIR__.'/../../resources/tooling/prettierignore.fragment'))
        ->toContain('resources/js/types/generated.manifest.json');
});

test('it rejects route configuration that could silently expose the artifact API', function (): void {
    config()->set('nvl-data.typescript.routes.enabled', 'false');

    expect(fn (): bool => app(GeneratedTypesRouteConfiguration::class)->enabled())
        ->toThrow(RuntimeException::class, 'routes.enabled must be a boolean');

    config()->set('nvl-data.typescript.routes.middleware', ['auth', null]);

    expect(fn (): array => app(GeneratedTypesRouteConfiguration::class)->middleware())
        ->toThrow(RuntimeException::class, 'must contain only non-empty, trimmed strings');

    config()->set('nvl-data.typescript.routes.middleware', []);

    expect(fn (): array => app(GeneratedTypesRouteConfiguration::class)->middleware())
        ->toThrow(RuntimeException::class, 'must be a non-empty list');

    config()->set('nvl-data.typescript.routes.middleware', 'auth:sanctum');

    expect(app(GeneratedTypesRouteConfiguration::class)->middleware())
        ->toBe(['auth:sanctum']);

    config()->set('nvl-data.typescript.routes.prefix', '/');

    expect(fn (): string => app(GeneratedTypesRouteConfiguration::class)->prefix())
        ->toThrow(RuntimeException::class, 'non-empty route-safe relative path');

    config()->set('nvl-data.typescript.routes.archive_enabled', 'false');

    expect(fn (): bool => app(GeneratedTypesRouteConfiguration::class)->archiveEnabled())
        ->toThrow(RuntimeException::class, 'archive_enabled must be a boolean');
});

test('partial consumer config preserves nested generated type route defaults', function (): void {
    config()->set('nvl-data', [
        'typescript' => [
            'routes' => [
                'enabled' => true,
            ],
        ],
    ]);

    (new DataServiceProvider(app()))->register();

    expect(config('nvl-data.typescript.routes.enabled'))->toBeTrue()
        ->and(config('nvl-data.typescript.routes.prefix'))->toBe('nvl/api/v1/data/types')
        ->and(config('nvl-data.typescript.routes.archive_enabled'))->toBeTrue();
});

test('it rejects ambiguous transformer enablement and non-length-aware pagination', function (): void {
    config()->set('nvl-data.typescript.configure_transformer', 'true');

    expect(fn (): mixed => (new DataServiceProvider(app()))->register())
        ->toThrow(RuntimeException::class, 'configure_transformer must be a boolean');

    expect(fn (): PaginatedCollection => PaginatedCollection::fromPaginator(
        new stdClass,
        DataTransformFixture::class,
    ))->toThrow(InvalidArgumentException::class, 'length-aware paginator');
});

test('it only permits generated output inside explicitly configured roots', function (): void {
    config()->set('nvl-data.typescript.allowed_roots', [$this->generatedTypesDirectory]);
    $guard = new TypeScriptPathGuard(config());

    expect($guard->outputDirectory($this->generatedTypesDirectory.'/types'))
        ->toBe($this->generatedTypesDirectory.'/types')
        ->and(fn (): string => $guard->outputDirectory(resource_path('js/types')))
        ->toThrow(RuntimeException::class, 'outside nvl-data.typescript.allowed_roots')
        ->and($guard->existingDirectory(__DIR__.'/../../src'))
        ->toBe(realpath(__DIR__.'/../../src'));
});

test('it rejects ambiguous relative artifact paths before generation', function (): void {
    expect(fn (): string => app(GeneratedArtifactSet::class)
        ->normalizeDeclarationPath('./generated.types.d.ts'))
        ->toThrow(RuntimeException::class, 'safe relative .d.ts paths');

    config()->set('nvl-data.typescript.manifest_file', 'metadata//generated.json');

    expect(fn (): string => app(GeneratedTypeFileCatalog::class)->manifestPath())
        ->toThrow(RuntimeException::class, 'safe relative JSON path');

    config()->set(
        'nvl-data.typescript.manifest_file',
        app(GeneratedArtifactSet::class)->transformerManifestFilename(),
    );

    expect(fn (): string => app(GeneratedTypeFileCatalog::class)->manifestPath())
        ->toThrow(RuntimeException::class, 'cannot overwrite the TypeScript transformer manifest');
});

test('it rejects transformer paths that normalize to the same declaration', function (): void {
    File::put(
        $this->generatedTypesDirectory.'/generated/media.d.ts',
        "declare namespace Nvl.Media {}\n",
    );
    $hash = md5_file($this->generatedTypesDirectory.'/generated/media.d.ts');
    File::put(
        $this->generatedTypesDirectory.'/typescript-transformer-manifest.json',
        json_encode([
            'generated/media.d.ts' => $hash,
            'generated\\media.d.ts' => $hash,
        ], JSON_THROW_ON_ERROR),
    );

    expect(fn (): array => app(GeneratedArtifactSet::class)
        ->paths($this->generatedTypesDirectory))
        ->toThrow(RuntimeException::class, 'contains duplicate path');
});

test('it rejects a split declaration that collides with the compatibility entrypoint', function (): void {
    $transformed = new class extends Transformed
    {
        /**
         * Create a minimal transformed symbol for writer-boundary testing.
         */
        public function __construct() {}

        /**
         * Return a conventional NVL Data namespace location.
         *
         * @return list<string>
         */
        public function getLocation(): array
        {
            return ['Nvl', 'Data'];
        }
    };
    $writer = new SplitNamespaceWriter(
        entrypointPath: 'generated/data.d.ts',
        scopeDirectory: 'generated',
    );

    expect(fn (): array => $writer->output(
        [$transformed],
        new TransformedCollection,
    ))->toThrow(RuntimeException::class, 'collides with a scoped declaration');
});

test('it serves a persisted hashable generated types manifest and supplemental scopes', function (): void {
    writeDataPackagePublication($this->generatedTypesDirectory, [
        'generated.types.d.ts' => "/// <reference path=\"./generated/media.d.ts\" />\n",
        'generated/media.d.ts' => "declare namespace Nvl.Media { type Identifier = string; }\n",
    ]);

    expect(Route::has('nvl.data.types.index'))->toBeTrue();

    $manifest = $this->getJson(route('nvl.data.types.index'));

    $manifest->assertSuccessful()
        ->assertJsonPath('meta.entrypoint.path', 'generated.types.d.ts')
        ->assertJsonPath('meta.entrypoint.url', '/nvl/api/v1/data/types/entrypoint')
        ->assertJsonPath('meta.archive.path', 'archive')
        ->assertJsonPath('meta.version', $manifest->json('meta.generatedAt'))
        ->assertJsonPath('meta.revision', trim((string) $manifest->headers->get('etag'), '"'))
        ->assertJsonStructure([
            'meta' => [
                'transformers' => [
                    'spatie/laravel-data',
                    'spatie/laravel-typescript-transformer',
                    'spatie/typescript-transformer',
                ],
            ],
        ])
        ->assertJsonPath('data.0.scope', 'media')
        ->assertJsonPath('data.0.url', '/nvl/api/v1/data/types/media');

    expect($manifest->headers->get('etag'))->not->toBeNull()
        ->and($manifest->headers->get('x-nvl-types-hash'))->not->toBeNull()
        ->and($manifest->headers->get('x-nvl-manifest-revision'))->not->toBeNull();

    $this->getJson(
        route('nvl.data.types.index'),
        ['If-None-Match' => (string) $manifest->headers->get('etag')],
    )->assertNotModified();

    $entrypoint = $this->get(route('nvl.data.types.entrypoint'));

    $entrypoint
        ->assertSuccessful()
        ->assertHeader('X-NVL-Type-Path', 'generated.types.d.ts');

    expect($entrypoint->getContent())->toContain('reference path');

    $scope = $this->get(route('nvl.data.types.show', ['scope' => 'media']));

    $scope
        ->assertSuccessful()
        ->assertHeader('X-NVL-Type-Scope', 'media');

    expect($scope->getContent())->toContain('Nvl.Media');
});

test('it supports application-specific generated type response headers', function (): void {
    config()->set('nvl-data.typescript.routes.headers_prefix', 'ACME');
    writeDataPackagePublication($this->generatedTypesDirectory, [
        'generated.types.d.ts' => "/// <reference path=\"./generated/media.d.ts\" />\n",
        'generated/media.d.ts' => "declare namespace Nvl.Media {}\n",
    ]);

    $this->get(route('nvl.data.types.show', ['scope' => 'media']))
        ->assertSuccessful()
        ->assertHeader('X-ACME-Type-Scope', 'media')
        ->assertHeader('X-ACME-Type-Path', 'generated/media.d.ts');
});

test('it creates route safe scopes from nested declaration filenames', function (): void {
    writeDataPackagePublication($this->generatedTypesDirectory, [
        'generated.types.d.ts' => "/// <reference path=\"./generated/media-types.d.ts\" />\n",
        'generated/media-types.d.ts' => "declare namespace Nvl.Media {}\n",
    ]);

    $this->getJson(route('nvl.data.types.index'))
        ->assertSuccessful()
        ->assertJsonPath('data.0.scope', 'media-types')
        ->assertJsonPath('data.0.url', '/nvl/api/v1/data/types/media-types');

    $this->get(route('nvl.data.types.show', ['scope' => 'media-types']))
        ->assertSuccessful();
});

test('it returns a retryable unavailable response before declarations are published', function (): void {
    $this->getJson(route('nvl.data.types.index'))
        ->assertServiceUnavailable()
        ->assertHeader('Retry-After', '5')
        ->assertJsonPath('message', 'Generated types are temporarily unavailable.');
});

test('it downloads a content-addressed archive of verified generated declarations', function (): void {
    if (! class_exists(ZipArchive::class)) {
        $this->markTestSkipped('The ZIP extension is unavailable.');
    }

    writeDataPackagePublication($this->generatedTypesDirectory, [
        'generated.types.d.ts' => "/// <reference path=\"./generated/media.d.ts\" />\n",
        'generated/media.d.ts' => "declare namespace Nvl.Media {}\n",
    ]);
    $archive = app(GeneratedTypeFileCatalog::class)->createArchive();
    $secondArchive = null;
    $zip = new ZipArchive;

    try {
        expect($zip->open($archive['path']))->toBeTrue();

        $entrypoint = $zip->statName('generated.types.d.ts');
        $scope = $zip->statName('generated/media.d.ts');

        expect($entrypoint)->toBeArray()
            ->and($scope)->toBeArray()
            ->and(gmdate('Y-m-d', $entrypoint['mtime']))->toBe('1980-01-01')
            ->and(gmdate('Y-m-d', $scope['mtime']))->toBe('1980-01-01');

        $secondArchive = app(GeneratedTypeFileCatalog::class)->createArchive();

        expect(hash_file('sha256', $secondArchive['path']))
            ->toBe(hash_file('sha256', $archive['path']));
    } finally {
        $zip->close();
        File::delete($archive['path']);

        if (is_array($secondArchive)) {
            File::delete($secondArchive['path']);
        }
    }

    $response = $this->get(route('nvl.data.types.archive'));

    $response->assertSuccessful()
        ->assertDownload();

    expect($response->headers->get('content-disposition'))
        ->toContain('generated-types-')
        ->toContain('.zip');
});

test('it generates split declarations from configured paths without an application provider', function (): void {
    config()->set('nvl-data.typescript.source_paths', [__DIR__.'/../Fixtures']);

    app()->forgetInstance(TypeScriptSourceRegistry::class);
    app()->forgetInstance(TypeScriptConfigurator::class);
    app()->forgetInstance(TypeScriptTransformerConfig::class);

    $this->artisan('typescript:transform')->assertSuccessful();

    expect($this->generatedTypesDirectory.'/generated.types.d.ts')
        ->toBeFile()
        ->and(File::get($this->generatedTypesDirectory.'/generated.types.d.ts'))
        ->toContain('generated/data.d.ts')
        ->and($this->generatedTypesDirectory.'/generated/data.d.ts')
        ->toBeFile()
        ->and(File::get($this->generatedTypesDirectory.'/generated/data.d.ts'))
        ->toContain('GeneratedTypeFixture')
        ->toContain('GeneratedPublicContract')
        ->toContain('PublicationState')
        ->not->toContain('GeneratedRenamedTypeFixture')
        ->not->toContain('GeneratedRenamedStatusFixture')
        ->not->toContain('HiddenDataFixture')
        ->toContain('items: Nvl.Data.Tests.Fixtures.GeneratedCollectionItemFixture[]')
        ->toContain('note?: string')
        ->toContain('publishedAt: string')
        ->toContain('reviewedAt: string')
        ->toContain('owner: any')
        ->not->toContain('readonly ');
});

test('it applies specific namespace mappings to split declaration scopes', function (): void {
    config()->set('nvl-data.typescript.source_paths', [__DIR__.'/../Fixtures']);
    config()->set('nvl-data.typescript.scope_mappings', [
        'Nvl\\Data\\Tests\\Fixtures' => 'shared-contracts',
    ]);

    app()->forgetInstance(TypeScriptSourceRegistry::class);
    app()->forgetInstance(TypeScriptConfigurator::class);
    app()->forgetInstance(TypeScriptTransformerConfig::class);

    $this->artisan('typescript:transform')->assertSuccessful();

    expect($this->generatedTypesDirectory.'/generated/shared-contracts.d.ts')
        ->toBeFile()
        ->and(File::get($this->generatedTypesDirectory.'/generated.types.d.ts'))
        ->toContain('generated/shared-contracts.d.ts');
});

test('it rejects ambiguous normalized namespace scope mappings', function (): void {
    config()->set('nvl-data.typescript.scope_mappings', [
        'Nvl.Data.Contracts' => 'contracts',
        'Nvl\\Data\\Contracts' => 'other-contracts',
    ]);

    expect(fn (): TypeScriptTransformerConfig => app(TypeScriptConfigurator::class)
        ->isolatedConfiguration($this->generatedTypesDirectory.'/isolated'))
        ->toThrow(
            RuntimeException::class,
            'TypeScript scope mapping [Nvl\\Data\\Contracts] is configured more than once',
        );
});

test('it fails deterministic symbol inspection on duplicate public types', function (): void {
    $firstDirectory = $this->generatedTypesDirectory.'/source-a';
    $secondDirectory = $this->generatedTypesDirectory.'/source-b';
    File::ensureDirectoryExists($firstDirectory);
    File::ensureDirectoryExists($secondDirectory);
    $source = <<<'PHP'
<?php

namespace Duplicate\First;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript(name: 'Contract', location: ['Duplicate', 'Types'])]
final class FirstContract
{
}
PHP;
    $secondSource = <<<'PHP'
<?php

namespace Duplicate\Second;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript(name: 'Contract', location: ['Duplicate', 'Types'])]
final class SecondContract
{
}
PHP;
    File::put($firstDirectory.'/FirstContract.php', $source);
    File::put($secondDirectory.'/SecondContract.php', $secondSource);
    require_once $firstDirectory.'/FirstContract.php';
    require_once $secondDirectory.'/SecondContract.php';

    $registry = app(TypeScriptSourceRegistry::class);
    $registry->register($firstDirectory, 'first/source');
    $registry->register($secondDirectory, 'second/source');

    expect(fn (): array => app(TypeScriptSourceInspector::class)->symbols())
        ->toThrow(
            RuntimeException::class,
            'TypeScript symbol [Duplicate.Types.Contract] is declared by both',
        );
});

test('it reports explicit TypeScript name and location overrides exactly', function (): void {
    $registry = app(TypeScriptSourceRegistry::class);
    $registry->register(__DIR__.'/../Fixtures');

    $symbol = collect(app(TypeScriptSourceInspector::class)->symbols())
        ->firstWhere('phpType', 'Nvl\\Data\\Tests\\Fixtures\\GeneratedRenamedTypeFixture');
    $unattributedData = collect(app(TypeScriptSourceInspector::class)->symbols())
        ->firstWhere('phpType', 'Nvl\\Data\\Tests\\Fixtures\\PaginationItemData');
    $renamedEnum = collect(app(TypeScriptSourceInspector::class)->symbols())
        ->firstWhere('phpType', 'Nvl\\Data\\Tests\\Fixtures\\GeneratedRenamedStatusFixture');
    $hiddenData = collect(app(TypeScriptSourceInspector::class)->symbols())
        ->firstWhere('phpType', 'Nvl\\Data\\Tests\\Fixtures\\HiddenDataFixture');

    expect($symbol)
        ->not->toBeNull()
        ->and($symbol['typescriptType'])
        ->toBe('Nvl.Data.Contracts.GeneratedPublicContract')
        ->and($unattributedData)
        ->not->toBeNull()
        ->and($unattributedData['typescriptType'])
        ->toBe('Nvl.Data.Tests.Fixtures.PaginationItemData')
        ->and($renamedEnum)
        ->not->toBeNull()
        ->and($renamedEnum['typescriptType'])
        ->toBe('Nvl.Data.Contracts.PublicationState')
        ->and($hiddenData)
        ->toBeNull();
});

test('it enforces source file limits before configuring a transformation', function (): void {
    config()->set('nvl-data.typescript.source_paths', [__DIR__.'/../Fixtures']);
    config()->set('nvl-data.typescript.max_source_files', 1);

    app()->forgetInstance(TypeScriptSourceRegistry::class);
    app()->forgetInstance(TypeScriptSourceInspector::class);
    app()->forgetInstance(TypeScriptConfigurator::class);

    expect(fn (): TypeScriptTransformerConfig => app(TypeScriptConfigurator::class)
        ->isolatedConfiguration($this->generatedTypesDirectory.'/isolated'))
        ->toThrow(
            RuntimeException::class,
            'TypeScript source discovery exceeds the configured file-count limit',
        );
});

test('it rejects memory limits that overflow the platform integer range', function (): void {
    config()->set('nvl-data.typescript.memory_limit', '999999999999999999999G');

    expect(fn (): TypeScriptTransformerConfig => app(TypeScriptConfigurator::class)
        ->isolatedConfiguration($this->generatedTypesDirectory.'/isolated'))
        ->toThrow(RuntimeException::class, 'exceeds the supported integer range');
});

test('it rejects ambiguous boolean transformer configuration', function (): void {
    config()->set('nvl-data.typescript.enum_union_types', 'true');

    expect(fn (): TypeScriptTransformerConfig => app(TypeScriptConfigurator::class)
        ->isolatedConfiguration($this->generatedTypesDirectory.'/isolated'))
        ->toThrow(
            RuntimeException::class,
            'Configuration [nvl-data.typescript.enum_union_types] must be a boolean',
        );
});

test('it validates configured PHP-to-TypeScript replacement maps', function (): void {
    config()->set('nvl-data.typescript.type_replacements', [
        'Invalid Type' => '',
    ]);

    expect(fn (): TypeScriptTransformerConfig => app(TypeScriptConfigurator::class)
        ->isolatedConfiguration($this->generatedTypesDirectory.'/isolated'))
        ->toThrow(
            RuntimeException::class,
            'must map a PHP type to a non-empty TypeScript type',
        );
});

test('it merges legacy replacements and fails generation or checks on unresolved references', function (): void {
    $sourceDirectory = $this->generatedTypesDirectory.'/replacement-source';
    File::ensureDirectoryExists($sourceDirectory);
    File::put($sourceDirectory.'/UploadContract.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Consumer\Types;

use Spatie\LaravelData\Data;

final class HostUpload
{
}

final class UploadContract extends Data
{
    public function __construct(public HostUpload $upload) {}
}
PHP);
    require_once $sourceDirectory.'/UploadContract.php';
    config()->set('nvl-data.typescript.source_paths', [$sourceDirectory]);

    app()->forgetInstance(TypeScriptSourceRegistry::class);
    app()->forgetInstance(TypeScriptConfigurator::class);
    app()->forgetInstance(TypeScriptTransformerConfig::class);

    $this->artisan('nvl:data:types:generate', ['--fail-on-warning' => true])
        ->expectsOutputToContain('transformer emitted warnings')
        ->assertFailed();

    config()->set('typescript-transformer.default_type_replacements', [
        'Consumer\Types\HostUpload' => 'File',
    ]);
    app()->forgetInstance(TypeScriptSourceRegistry::class);
    app()->forgetInstance(TypeScriptConfigurator::class);
    app()->forgetInstance(TypeScriptTransformerConfig::class);

    $this->artisan('nvl:data:types:generate')->assertSuccessful();

    $declarations = collect(File::allFiles($this->generatedTypesDirectory))
        ->filter(static fn (SplFileInfo $file): bool => $file->getExtension() === 'ts')
        ->map(static fn (SplFileInfo $file): string => File::get($file->getPathname()))
        ->implode("\n");

    expect($declarations)->toContain('upload: File');

    config()->set('typescript-transformer.default_type_replacements', []);
    app()->forgetInstance(TypeScriptSourceRegistry::class);
    app()->forgetInstance(TypeScriptConfigurator::class);
    app()->forgetInstance(TypeScriptTransformerConfig::class);

    $this->artisan('nvl:data:types:check', ['--fail-on-warning' => true])
        ->expectsOutputToContain('unresolved transformer warnings')
        ->assertFailed();
});

test('its staged generation command publishes an integrity manifest and passes a fresh check', function (): void {
    config()->set('nvl-data.typescript.source_paths', [__DIR__.'/../Fixtures']);

    app()->forgetInstance(TypeScriptSourceRegistry::class);
    app()->forgetInstance(TypeScriptConfigurator::class);
    app()->forgetInstance(TypeScriptTransformerConfig::class);

    $this->artisan('nvl:data:types:generate')->assertSuccessful();

    expect($this->generatedTypesDirectory.'/generated.manifest.json')
        ->toBeFile()
        ->and(File::get($this->generatedTypesDirectory.'/generated.manifest.json'))
        ->toContain('"schemaVersion": 2')
        ->toContain('GeneratedDataContractFixture')
        ->toContain('Nvl.Data.Contracts.GeneratedPublicContract');

    $this->artisan('nvl:data:types:check')->assertSuccessful();
});

test('its freshness check ignores checkout mtimes when declarations are byte identical', function (): void {
    config()->set('nvl-data.typescript.source_paths', [__DIR__.'/../Fixtures']);

    app()->forgetInstance(TypeScriptSourceRegistry::class);
    app()->forgetInstance(TypeScriptConfigurator::class);
    app()->forgetInstance(TypeScriptTransformerConfig::class);

    $this->artisan('nvl:data:types:generate')->assertSuccessful();

    foreach (app(GeneratedArtifactSet::class)->paths($this->generatedTypesDirectory) as $path) {
        expect(touch(
            $this->generatedTypesDirectory.DIRECTORY_SEPARATOR.$path,
            time() + 60,
        ))->toBeTrue();
    }

    $this->artisan('nvl:data:types:check')->assertSuccessful();
});

test('it restores the prior artifact set when staged publication fails', function (): void {
    writeDataPackagePublication($this->generatedTypesDirectory, [
        'generated.types.d.ts' => "/// <reference path=\"./generated/old.d.ts\" />\n",
        'generated/old.d.ts' => "declare namespace Nvl.Old {}\n",
    ]);
    $stagingDirectory = $this->generatedTypesDirectory.'-staging';
    $failedPath = $this->generatedTypesDirectory.'/generated/new.d.ts';
    $files = new class($failedPath) extends Filesystem
    {
        private bool $hasFailed = false;

        /**
         * Create a filesystem that fails one selected publication replacement.
         */
        public function __construct(
            private readonly string $failedPath,
        ) {}

        /**
         * Fail once for the selected target and delegate every other replacement.
         *
         * @param  string  $path
         * @param  string  $content
         * @param  int|null  $mode
         */
        public function replace($path, $content, $mode = null): void
        {
            if (! $this->hasFailed && $path === $this->failedPath) {
                $this->hasFailed = true;

                throw new RuntimeException('Simulated publication failure.');
            }

            parent::replace($path, $content, $mode);
        }
    };
    $publisher = new GeneratedTypesPublisher(
        config: config(),
        files: $files,
        pathGuard: app(TypeScriptPathGuard::class),
        artifacts: app(GeneratedArtifactSet::class),
        catalog: app(GeneratedTypeFileCatalog::class),
        manifestWriter: app(GeneratedTypesManifestWriter::class),
        lock: app(GeneratedTypesLock::class),
    );

    writeDataPackageTransform($stagingDirectory, [
        'generated.types.d.ts' => "/// <reference path=\"./generated/new.d.ts\" />\n",
        'generated/new.d.ts' => "declare namespace Nvl.New {}\n",
    ]);

    try {
        expect(fn (): string => $publisher->publish($stagingDirectory))
            ->toThrow(RuntimeException::class, 'Simulated publication failure.');

        expect(File::get($this->generatedTypesDirectory.'/generated.types.d.ts'))
            ->toContain('generated/old.d.ts')
            ->and($this->generatedTypesDirectory.'/generated/old.d.ts')
            ->toBeFile()
            ->and($this->generatedTypesDirectory.'/generated/new.d.ts')
            ->not->toBeFile();

        $this->get(route('nvl.data.types.show', ['scope' => 'old']))
            ->assertSuccessful();
        $this->get(route('nvl.data.types.show', ['scope' => 'new']))
            ->assertNotFound();
    } finally {
        File::deleteDirectory($stagingDirectory);
    }
});

test('it rejects publication through output symlinks before replacing any artifacts', function (string $kind): void {
    $stagingDirectory = $this->generatedTypesDirectory.'-staging';
    $outsideDirectory = $this->generatedTypesDirectory.'-outside';
    File::ensureDirectoryExists($outsideDirectory);
    File::put($outsideDirectory.'/sentinel', '{}');
    File::put($this->generatedTypesDirectory.'/generated.types.d.ts', 'original entrypoint');

    $link = match ($kind) {
        'directory' => $this->generatedTypesDirectory.'/generated',
        'declaration' => $this->generatedTypesDirectory.'/generated.types.d.ts',
        'transformer manifest' => $this->generatedTypesDirectory.'/typescript-transformer-manifest.json',
        'integrity manifest' => $this->generatedTypesDirectory.'/generated.manifest.json',
    };

    if ($kind === 'directory') {
        File::deleteDirectory($link);
    } else {
        File::delete($link);
    }

    expect(symlink($kind === 'directory' ? $outsideDirectory : $outsideDirectory.'/sentinel', $link))
        ->toBeTrue();
    $originalEntrypoint = File::get($this->generatedTypesDirectory.'/generated.types.d.ts');
    writeDataPackageTransform($stagingDirectory, [
        'generated.types.d.ts' => "/// <reference path=\"./generated/core.d.ts\" />\n",
        'generated/core.d.ts' => "declare namespace Nvl.Core {}\n",
    ]);

    try {
        expect(fn (): string => app(GeneratedTypesPublisher::class)->publish($stagingDirectory))
            ->toThrow(RuntimeException::class);
        expect(File::get($outsideDirectory.'/sentinel'))->toBe('{}')
            ->and(File::get($this->generatedTypesDirectory.'/generated.types.d.ts'))->toBe($originalEntrypoint)
            ->and(file_exists($outsideDirectory.'/core.d.ts'))->toBeFalse();
    } finally {
        unlink($link);
        File::deleteDirectory($stagingDirectory);
        File::deleteDirectory($outsideDirectory);
    }
})->with(['directory', 'declaration', 'transformer manifest', 'integrity manifest']);

test('its generated types check rejects a tampered integrity manifest', function (): void {
    config()->set('nvl-data.typescript.source_paths', [__DIR__.'/../Fixtures']);

    app()->forgetInstance(TypeScriptSourceRegistry::class);
    app()->forgetInstance(TypeScriptConfigurator::class);
    app()->forgetInstance(TypeScriptTransformerConfig::class);

    $this->artisan('nvl:data:types:generate')->assertSuccessful();

    $manifestPath = $this->generatedTypesDirectory.'/generated.manifest.json';
    $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $manifest['hash'] = str_repeat('a', 64);
    File::put(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    $this->artisan('nvl:data:types:check')
        ->expectsOutputToContain('integrity manifest are stale')
        ->assertFailed();
});

test('it fails fast when another generated types build holds the generation lock', function (): void {
    $lock = new LockableFile(storage_path('app/nvl-data/locks/generation.lock'), 'c+');
    $lock->getExclusiveLock();

    try {
        $this->artisan('nvl:data:types:generate')
            ->expectsOutputToContain('Another generated-types build is already running.')
            ->assertFailed();
    } finally {
        $lock->close();
    }
});

test('it returns immediately while a publication lock is contended', function (): void {
    writeDataPackagePublication($this->generatedTypesDirectory, [
        'generated.types.d.ts' => "/// <reference path=\"./generated/media.d.ts\" />\n",
        'generated/media.d.ts' => "declare namespace Nvl.Media {}\n",
    ]);
    $lock = new LockableFile(storage_path('app/nvl-data/locks/publication.lock'), 'c+');
    $lock->getExclusiveLock();
    $startedAt = hrtime(true);

    try {
        $this->getJson(route('nvl.data.types.index'))
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '5');
    } finally {
        $lock->close();
    }

    expect((hrtime(true) - $startedAt) / 1_000_000_000)->toBeLessThan(1.0);
});

test('it bounds integrity manifests before writing them', function (): void {
    config()->set('nvl-data.typescript.max_manifest_bytes', 1);

    expect(fn (): string => app(GeneratedTypesManifestWriter::class)->writeManifest([
        'oversized' => str_repeat('x', 100),
    ]))
        ->toThrow(RuntimeException::class, 'integrity manifest exceeds its configured size limit')
        ->and($this->generatedTypesDirectory.'/generated.manifest.json')
        ->not->toBeFile();
});

test('it rejects manifest metadata changes without a matching revision', function (): void {
    writeDataPackagePublication($this->generatedTypesDirectory, [
        'generated.types.d.ts' => "declare namespace Nvl.Data {}\n",
    ]);
    $manifestPath = $this->generatedTypesDirectory.'/generated.manifest.json';
    $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $manifest['packages']['nvl/core'] = 'tampered';
    File::put(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    $this->getJson(route('nvl.data.types.index'))
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'Generated types are temporarily unavailable.');
});

test('it rejects checksum-tampered declarations without leaking filesystem paths', function (): void {
    writeDataPackagePublication($this->generatedTypesDirectory, [
        'generated.types.d.ts' => "/// <reference path=\"./generated/media.d.ts\" />\n",
        'generated/media.d.ts' => "declare namespace Nvl.Media {}\n",
    ]);
    File::put(
        $this->generatedTypesDirectory.'/generated/media.d.ts',
        "declare namespace Nvl.Tampered {}\n",
    );

    $response = $this->get(route('nvl.data.types.show', ['scope' => 'media']));

    $response
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'Generated types are temporarily unavailable.');

    expect($response->getContent())->not->toContain($this->generatedTypesDirectory);
});

test('it never exposes declarations omitted from the transformer manifest', function (): void {
    writeDataPackagePublication($this->generatedTypesDirectory, [
        'generated.types.d.ts' => "/// <reference path=\"./generated/media.d.ts\" />\n",
        'generated/media.d.ts' => "declare namespace Nvl.Media {}\n",
    ]);
    File::put(
        $this->generatedTypesDirectory.'/generated/private.d.ts',
        "declare namespace Nvl.Private {}\n",
    );

    $this->getJson(route('nvl.data.types.index'))
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.scope', 'media');

    $this->get(route('nvl.data.types.show', ['scope' => 'private']))
        ->assertNotFound();
});

test('it rejects incomplete transformer publications with stale inventory checksums', function (): void {
    File::put(
        $this->generatedTypesDirectory.'/generated.types.d.ts',
        "declare namespace Nvl.Data {}\n",
    );
    File::put(
        $this->generatedTypesDirectory.'/typescript-transformer-manifest.json',
        json_encode([
            'generated.types.d.ts' => str_repeat('a', 32),
        ], JSON_THROW_ON_ERROR),
    );

    expect(fn (): string => app(GeneratedTypesManifestWriter::class)->write())
        ->toThrow(RuntimeException::class, 'does not match the transformer manifest checksum');
});

test('it rejects generated declaration symlinks that escape the output directory', function (): void {
    $outsidePath = tempnam(storage_path('framework/testing'), 'nvl-data-outside-');

    if ($outsidePath === false) {
        throw new RuntimeException('Unable to create the symlink test target.');
    }

    File::put($outsidePath, "declare namespace Nvl.Outside {}\n");
    File::put(
        $this->generatedTypesDirectory.'/generated.types.d.ts',
        "/// <reference path=\"./generated/media.d.ts\" />\n",
    );
    symlink($outsidePath, $this->generatedTypesDirectory.'/generated/media.d.ts');
    File::put(
        $this->generatedTypesDirectory.'/typescript-transformer-manifest.json',
        json_encode([
            'generated.types.d.ts' => md5_file($this->generatedTypesDirectory.'/generated.types.d.ts'),
            'generated/media.d.ts' => md5_file($outsidePath),
        ], JSON_THROW_ON_ERROR),
    );

    try {
        expect(fn (): string => app(GeneratedTypesManifestWriter::class)->write())
            ->toThrow(RuntimeException::class, 'symlinks cannot leave the output directory');
    } finally {
        File::delete($this->generatedTypesDirectory.'/generated/media.d.ts');
        File::delete($outsidePath);
    }
});

test('it rejects TypeScript source symlinks that escape their registered directory', function (): void {
    $sourceDirectory = $this->generatedTypesDirectory.'/source';
    $outsidePath = tempnam(storage_path('framework/testing'), 'nvl-data-source-outside-');

    if ($outsidePath === false) {
        throw new RuntimeException('Unable to create the source symlink test target.');
    }

    File::ensureDirectoryExists($sourceDirectory);
    File::put($outsidePath, "<?php\n\nfinal class EscapedTypeScriptSource {}\n");
    $symlink = $sourceDirectory.'/EscapedTypeScriptSource.php';
    symlink($outsidePath, $symlink);
    app(TypeScriptSourceRegistry::class)->register($sourceDirectory);

    try {
        expect(function (): void {
            app(TypeScriptSourceInspector::class)->assertWithinLimits();
        })
            ->toThrow(RuntimeException::class, 'source symlinks cannot leave');
    } finally {
        File::delete($symlink);
        File::delete($outsidePath);
    }
});

test('it enforces generated file bounds under oversized publications', function (): void {
    config()->set('nvl-data.typescript.max_generated_files', 2);

    File::put(
        $this->generatedTypesDirectory.'/generated.types.d.ts',
        "/// <reference path=\"./generated/media.d.ts\" />\n",
    );
    File::put(
        $this->generatedTypesDirectory.'/generated/media.d.ts',
        "declare namespace Nvl.Media { type Identifier = string; }\n",
    );
    File::put(
        $this->generatedTypesDirectory.'/generated/other.d.ts',
        "declare namespace Nvl.Other {}\n",
    );
    File::put(
        $this->generatedTypesDirectory.'/typescript-transformer-manifest.json',
        json_encode([
            'generated.types.d.ts' => md5_file($this->generatedTypesDirectory.'/generated.types.d.ts'),
            'generated/media.d.ts' => md5_file($this->generatedTypesDirectory.'/generated/media.d.ts'),
            'generated/other.d.ts' => md5_file($this->generatedTypesDirectory.'/generated/other.d.ts'),
        ], JSON_THROW_ON_ERROR),
    );

    $this->getJson(route('nvl.data.types.index'))
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'Generated types are temporarily unavailable.');
});

test('it enforces archive bounds but short circuits matching conditional requests', function (): void {
    if (! class_exists(ZipArchive::class)) {
        $this->markTestSkipped('The ZIP extension is unavailable.');
    }

    writeDataPackagePublication($this->generatedTypesDirectory, [
        'generated.types.d.ts' => "/// <reference path=\"./generated/media.d.ts\" />\n",
        'generated/media.d.ts' => str_repeat('declare namespace Nvl.Media {}', 10),
    ]);
    $manifest = $this->getJson(route('nvl.data.types.index'))->assertSuccessful();
    config()->set('nvl-data.typescript.routes.archive_max_bytes', 1);

    $this->get(route('nvl.data.types.archive'))
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'Generated types are temporarily unavailable.');

    $this->get(
        route('nvl.data.types.archive'),
        ['If-None-Match' => '"'.$manifest->json('meta.hash').'"'],
    )->assertNotModified();
});

test('it serves repeated manifest reads from one stable persisted publication', function (): void {
    writeDataPackagePublication($this->generatedTypesDirectory, [
        'generated.types.d.ts' => "/// <reference path=\"./generated/media.d.ts\" />\n",
        'generated/media.d.ts' => "declare namespace Nvl.Media {}\n",
    ]);
    config()->set('nvl-data.typescript.max_source_files', 1);
    $expectedHash = $this->getJson(route('nvl.data.types.index'))
        ->assertSuccessful()
        ->json('meta.hash');

    foreach (range(1, 50) as $requestNumber) {
        $this->getJson(route('nvl.data.types.index'))
            ->assertSuccessful()
            ->assertJsonPath('meta.hash', $expectedHash);
    }
});

test('it distinguishes create filtering from explicit patch clears', function (): void {
    $payload = new class(null, new Optional) extends Data
    {
        use DataTransform;

        public function __construct(
            public readonly ?string $description,
            public readonly string|Optional $name,
        ) {}
    };

    expect($payload->toModelFiltered())->toBe([])
        ->and($payload->toModelPatch())->toBe(['description' => null]);
});

test('it recursively maps model keys and backed enums without changing list indexes', function (): void {
    $payload = new class extends Data
    {
        use DataTransform;

        /**
         * @param  array<string, mixed>  $profileData
         * @param  list<array<string, string>>  $lineItems
         */
        public function __construct(
            public readonly array $profileData = [
                'displayName' => 'Published profile',
                'status' => DataPackageStatus::Published,
            ],
            public readonly array $lineItems = [
                ['externalId' => 'first'],
                ['externalId' => 'second'],
            ],
        ) {}
    };

    expect($payload->toModel())->toBe([
        'profile_data' => [
            'display_name' => 'Published profile',
            'status' => 'published',
        ],
        'line_items' => [
            ['external_id' => 'first'],
            ['external_id' => 'second'],
        ],
    ]);
});

test('it recursively filters nested optional and null values while preserving patch clears', function (): void {
    $payload = new class extends Data
    {
        use DataTransform;

        /**
         * @param  array<string, mixed>  $profileData
         */
        public function __construct(
            public readonly array $profileData = [
                'displayName' => 'Profile',
                'nickname' => null,
                'internalCode' => new Optional,
                'tags' => ['first', new Optional, 'third'],
            ],
        ) {}
    };

    expect($payload->toModelFiltered())->toBe([
        'profile_data' => [
            'display_name' => 'Profile',
            'tags' => ['first', 'third'],
        ],
    ])->and($payload->toModelPatch())->toBe([
        'profile_data' => [
            'display_name' => 'Profile',
            'nickname' => null,
            'tags' => ['first', 'third'],
        ],
    ]);
});

test('it rejects normalized key collisions and pathological nesting', function (): void {
    $collision = new class extends Data
    {
        use DataTransform;

        public function __construct(
            public readonly string $displayName = 'first',
            public readonly string $display_name = 'second',
        ) {}
    };

    expect(fn (): array => $collision->toModel())
        ->toThrow(LogicException::class, 'normalize to [display_name]');

    $nested = 'value';

    foreach (range(1, 66) as $level) {
        $nested = ['level'.$level => $nested];
    }

    $deepPayload = new class($nested) extends Data
    {
        use DataTransform;

        /**
         * @param  array<string, mixed>  $nested
         */
        public function __construct(
            public readonly array $nested,
        ) {}
    };

    expect(fn (): array => $deepPayload->toModel())
        ->toThrow(OverflowException::class, 'maximum nesting depth');
});

test('its manifest command displays and persists the verified artifact contract', function (): void {
    writeDataPackagePublication($this->generatedTypesDirectory, [
        'generated.types.d.ts' => "declare namespace Nvl.Data {}\n",
    ]);

    $this->artisan('nvl:data:types:manifest')
        ->expectsOutputToContain('"schemaVersion": 2')
        ->assertSuccessful();

    File::delete($this->generatedTypesDirectory.'/generated.manifest.json');

    $this->artisan('nvl:data:types:manifest', ['--write' => true])
        ->expectsOutputToContain($this->generatedTypesDirectory.'/generated.manifest.json')
        ->assertSuccessful();

    expect($this->generatedTypesDirectory.'/generated.manifest.json')->toBeFile();
});

test('its source registry validates configuration ownership and deterministic priority', function (): void {
    config()->set('nvl-data.typescript.source_paths', 'not-a-list');

    expect(fn (): TypeScriptSourceRegistry => new TypeScriptSourceRegistry(
        config(),
        app(TypeScriptPathGuard::class),
    ))->toThrow(RuntimeException::class, 'source_paths must be an array');

    config()->set('nvl-data.typescript.source_paths', [42]);

    expect(fn (): TypeScriptSourceRegistry => new TypeScriptSourceRegistry(
        config(),
        app(TypeScriptPathGuard::class),
    ))->toThrow(RuntimeException::class, 'source path must be a string');

    $firstSource = $this->generatedTypesDirectory.'/source-first';
    $secondSource = $this->generatedTypesDirectory.'/source-second';
    File::ensureDirectoryExists($firstSource);
    File::ensureDirectoryExists($secondSource);
    config()->set('nvl-data.typescript.source_paths', []);

    $registry = new TypeScriptSourceRegistry(config(), app(TypeScriptPathGuard::class));
    $registry
        ->register($firstSource, priority: 10)
        ->register($firstSource, package: 'application/contracts', priority: 50)
        ->registerMany([$secondSource], package: 'nvl/example', priority: 25);

    expect($registry->descriptors())->toBe([
        [
            'path' => realpath($firstSource),
            'package' => 'application/contracts',
            'priority' => 50,
        ],
        [
            'path' => realpath($secondSource),
            'package' => 'nvl/example',
            'priority' => 25,
        ],
    ])->and($registry->all())->toBe([
        realpath($firstSource),
        realpath($secondSource),
    ]);

    expect(fn (): TypeScriptSourceRegistry => $registry->register(
        $firstSource,
        package: 'conflicting/provider',
    ))->toThrow(RuntimeException::class, 'already registered by [application/contracts]');
});

test('its data transform exposes scoped validation and translated consumer labels', function (): void {
    $payload = new class extends Data
    {
        use DataTransform;

        public function __construct(
            public readonly string $displayName = 'Example',
        ) {}

        /**
         * @return array<string, list<string>>
         */
        public static function rules(): array
        {
            return ['displayName' => ['required', 'string']];
        }
    };
    $translationDirectory = $this->generatedTypesDirectory.'/translations';
    File::ensureDirectoryExists($translationDirectory.'/en');
    File::put(
        $translationDirectory.'/en/validation.php',
        <<<'PHP'
<?php

return [
    'attributes' => [
        'display_name' => 'Display name',
    ],
    'custom' => [
        'display_name' => [
            'required' => 'A display name is required.',
        ],
    ],
];
PHP,
    );
    app('translator')->addNamespace('demo', $translationDirectory);

    expect($payload::scopedRules('payload.'))->toBe([
        'payload.displayName' => ['required', 'string'],
    ])->and($payload::translatedAttributes('demo::validation'))->toBe([
        'display_name' => 'Display name',
        'displayName' => 'Display name',
    ])->and($payload::translatedMessages('demo::validation'))->toBe([
        'display_name' => ['required' => 'A display name is required.'],
    ])->and($payload::translatedAttributes('missing::validation'))->toBe([])
        ->and($payload::translatedMessages('missing::validation'))->toBe([]);
});

test('its data transform rejects collisions inside nested consumer payloads', function (): void {
    $payload = new DataTransformFixture(
        description: null,
        name: new Optional,
        metadata: [
            'displayName' => 'first',
            'display_name' => 'second',
        ],
    );

    expect(fn (): array => $payload->toModel())
        ->toThrow(LogicException::class, 'normalize to [display_name]');
});

/**
 * Write one test publication and persist the integrity manifest used by HTTP delivery.
 *
 * @param  array<string, string>  $files
 */
function writeDataPackagePublication(string $directory, array $files): void
{
    writeDataPackageTransform($directory, $files);
    app(GeneratedTypesManifestWriter::class)->write();
}

/**
 * Write one transformer-owned declaration set without its NVL integrity manifest.
 *
 * @param  array<string, string>  $files
 */
function writeDataPackageTransform(string $directory, array $files): void
{
    $transformerManifest = [];

    foreach ($files as $path => $contents) {
        $absolutePath = $directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, $contents);
        $transformerManifest[$path] = md5($contents);
    }

    File::put(
        $directory.'/typescript-transformer-manifest.json',
        json_encode(
            $transformerManifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ),
    );
}

test('it rejects malformed transformer output and discovery options before publishing declarations', function (string $key, mixed $value, string $message): void {
    config()->set($key, $value);
    expect(fn (): TypeScriptTransformerConfig => app(TypeScriptConfigurator::class)->isolatedConfiguration($this->generatedTypesDirectory.'/isolated'))
        ->toThrow(RuntimeException::class, $message)
        ->and(is_file($this->generatedTypesDirectory.'/generated.types.d.ts'))->toBeFalse();
})->with([
    'invalid declaration filename' => ['nvl-data.typescript.output_file', [], 'must be a safe .d.ts path'],
    'unknown writer' => ['nvl-data.typescript.writer', 'unknown', 'must be [global] or [split]'],
    'invalid scope directory' => ['nvl-data.typescript.split_directory', [], 'must be a safe relative path'],
    'invalid scope map' => ['nvl-data.typescript.scope_mappings', false, 'must be an array'],
    'invalid scope identity' => ['nvl-data.typescript.scope_mappings', ['invalid space' => 'scope'], 'namespace and route-safe scope'],
    'unknown model type' => ['nvl-data.typescript.model_type', 'object', 'must be [any] or [unknown]'],
    'invalid replacement map' => ['typescript-transformer.type_replacements', false, 'must be a PHP-to-TypeScript replacement map'],
    'empty memory policy' => ['nvl-data.typescript.memory_limit', '', 'must be a non-empty PHP memory limit'],
    'invalid memory units' => ['nvl-data.typescript.memory_limit', '20T', 'Invalid PHP memory limit'],
    'memory multiplication overflow' => ['nvl-data.typescript.memory_limit', PHP_INT_MAX.'G', 'exceeds the supported integer range'],
]);

test('it preserves the host memory limit when the transformer policy is unlimited', function (): void {
    config()->set('nvl-data.typescript.memory_limit', '-1');
    $before = ini_get('memory_limit');
    app(TypeScriptConfigurator::class)->isolatedConfiguration($this->generatedTypesDirectory.'/isolated');
    expect(ini_get('memory_limit'))->toBe($before);
});

test('it rejects a malformed configured output directory when no host override is supplied', function (): void {
    config()->set('nvl-data.typescript.output_directory', false);
    expect(fn () => app(TypeScriptConfigurator::class)->configure(new TypeScriptTransformerConfigFactory))
        ->toThrow(RuntimeException::class, 'must be a non-empty path');
});
