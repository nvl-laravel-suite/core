<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Unit;

use Closure;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;

/**
 * Declare two installed capabilities without loading any fixture PHP source.
 *
 * @return array<string, array<string, mixed>>
 */
function consumerCatalogFixtures(): array
{
    return [
        'nvl/core' => [
            'schema_version' => 1,
            'package' => 'nvl/core',
            'psr4' => ['Nvl\\Support\\' => 'support/src/', 'Nvl\\Data\\' => 'data/src/'],
            'symbols' => [
                'Nvl\\Support\\Contracts\\LocaleCatalog' => ['kind' => 'interface', 'file' => 'support/src/Contracts/LocaleCatalog.php', 'methods' => ['supported'], 'properties' => [], 'constants' => []],
                'Nvl\\Data\\Data\\PaginationMeta' => ['kind' => 'class', 'file' => 'data/src/Data/PaginationMeta.php', 'methods' => ['__construct'], 'properties' => ['currentPage'], 'constants' => []],
            ],
            'models' => [],
            'capability_relations' => [],
            'tables' => [],
        ],
        'nvl/comments' => [
            'schema_version' => 1,
            'package' => 'nvl/comments',
            'psr4' => ['Nvl\\Comments\\' => 'src/'],
            'symbols' => [
                'Nvl\\Comments\\Models\\Comment' => ['kind' => 'class', 'file' => 'src/Models/Comment.php', 'methods' => ['save'], 'properties' => [], 'constants' => ['TABLE']],
                'Nvl\\Comments\\Contracts\\ListOwnerCommentSummariesContract' => ['kind' => 'interface', 'file' => 'src/Contracts/ListOwnerCommentSummariesContract.php', 'methods' => ['execute'], 'properties' => [], 'constants' => []],
                'Nvl\\Comments\\Traits\\InteractsWithComments' => ['kind' => 'trait', 'file' => 'src/Traits/InteractsWithComments.php', 'methods' => ['comments', 'scopeWithNvlVisibleCommentCount'], 'properties' => [], 'constants' => []],
            ],
            'models' => [
                'Nvl\\Comments\\Models\\Comment' => ['read' => ['id', 'revision'], 'identity_methods' => ['getKey', 'getKeyName', 'getMorphClass', 'getRouteKey', 'getRouteKeyName', 'is', 'isNot', 'relationLoaded'], 'capability_relations' => []],
            ],
            'capability_relations' => ['Nvl\\Comments\\Traits\\InteractsWithComments' => ['comments']],
            'tables' => ['comments' => 'nvl_comments_comments', 'revisions' => 'nvl_comments_revisions'],
        ],
    ];
}

/**
 * Exercise discovery in a process with no Laravel or registered vendor inventory.
 *
 * @param  array<string, array<string, mixed>>  $catalogs
 * @param  Closure(array<string, string>, array<string, mixed>&): void|null  $prepare
 * @return array<string, mixed>
 */
function consumerCatalogProbe(array $catalogs, ?Closure $prepare = null): array
{
    $directory = sys_get_temp_dir().'/nvl-consumer-catalog-'.bin2hex(random_bytes(10));
    mkdir($directory, 0700, true);
    $roots = [];
    $versions = [];

    try {
        foreach ($catalogs as $package => $catalog) {
            $root = $directory.'/'.str_replace('/', '-', $package);
            $roots[$package] = $root;
            $versions[$package] = ['install_path' => $root, 'type' => 'library'];

            foreach ($catalog['psr4'] ?? [] as $relative) {
                mkdir($root.'/'.$relative, 0700, true);
            }

            foreach ($catalog['symbols'] ?? [] as $symbol) {
                $path = $root.'/'.$symbol['file'];
                if (! is_dir(dirname($path))) {
                    mkdir(dirname($path), 0700, true);
                }
                file_put_contents($path, '<?php throw new LogicException("Catalog discovery loaded fixture PHP.");');
            }

            $path = $root.($package === 'nvl/core' ? '/support/resources/consumer-api.json' : '/resources/consumer-api.json');
            mkdir(dirname($path), 0700, true);
            file_put_contents($path, json_encode($catalog, JSON_THROW_ON_ERROR));
        }

        $prepare?->__invoke($roots, $versions);
        $host = $directory.'/host/Nvl/Comments/Models/Comment.php';
        mkdir(dirname($host), 0700, true);
        file_put_contents($host, '<?php namespace Nvl\\Comments\\Models; class Comment {}');
        file_put_contents($directory.'/installed.json', json_encode(['root' => ['name' => 'example/consumer'], 'versions' => $versions], JSON_THROW_ON_ERROR));
        $repository = dirname(__DIR__, 6);
        $script = <<<'PHP'
require $argv[1].'/vendor/composer/ClassLoader.php';
require $argv[1].'/vendor/composer/InstalledVersions.php';
$loader = new Composer\Autoload\ClassLoader;
$loader->addPsr4('Nvl\\Support\\', $argv[1].'/packages/nvl/core/support/src');
$loader->addPsr4('Nvl\\Data\\', $argv[1].'/packages/nvl/core/data/src');
$loader->register();
Composer\InstalledVersions::reload(json_decode(file_get_contents($argv[2].'/installed.json'), true, flags: JSON_THROW_ON_ERROR));
try {
    $catalog = Nvl\Support\Consumer\ConsumerApiCatalog::installed();
    $symbol = $catalog->symbol('Nvl\\Comments\\Contracts\\ListOwnerCommentSummariesContract');
    $model = $catalog->model('Nvl\\Comments\\Models\\Comment');
    $immutable = false;
    try { $model->readableFields[] = 'password'; } catch (Error) { $immutable = true; }
    $roots = $catalog->installedRoots();
    $core = $roots['nvl/core'] ?? null;
    $comment = $catalog->symbol('Nvl\\Comments\\Models\\Comment');
    echo json_encode([
        'error' => null,
        'symbol' => $symbol === null ? null : [$symbol->package, $symbol->class, $symbol->kind, $symbol->methods, $symbol->properties, $symbol->constants],
        'core_support' => $catalog->symbol('Nvl\\Support\\Contracts\\LocaleCatalog')?->class,
        'core_data' => $catalog->symbol('Nvl\\Data\\Data\\PaginationMeta')?->properties,
        'unknown' => $catalog->symbol('Nvl\\Media\\Models\\Media'),
        'model' => $model === null ? null : [$model->class, $model->readableFields, $model->identityMethods, $model->capabilityRelations],
        'table' => $catalog->tableOwner('nvl_comments_comments'),
        'internal_table' => $catalog->tableOwner('nvl_comments_revisions'),
        'missing_table' => $catalog->tableOwner('nvl_media'),
        'traits' => $catalog->capabilityRelations('Nvl\\Comments\\Traits\\InteractsWithComments'),
        'unknown_trait' => $catalog->capabilityRelations('Host\\HasComments'),
        'owner' => $comment === null ? null : $catalog->packageForFile($comment->sourcePath()),
        'host_owner' => $catalog->packageForFile($argv[2].'/host/Nvl/Comments/Models/Comment.php'),
        'core_roots' => $core,
        'immutable' => $immutable,
        'loaded' => array_values(array_filter(get_declared_classes(), static fn (string $class): bool => str_starts_with($class, 'Illuminate\\') || str_starts_with($class, 'Nvl\\Suite\\') || str_starts_with($class, 'PhpParser\\'))),
        'fixture_loaded' => class_exists('Nvl\\Comments\\Models\\Comment', false),
        'alias' => $catalog->symbol('Nvl\\Comments\\Contracts\\LegacyLocaleCatalog')?->aliasOf ?? null,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['error' => $exception->getMessage(), 'exception' => $exception::class], JSON_THROW_ON_ERROR);
}
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, $repository, $directory]);
        $process->mustRun();

        /** @var array<string, mixed> $result */
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $result;
    } finally {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}

it('discovers Core and one installed leaf without loading Laravel, siblings or fixture PHP', function (): void {
    $result = consumerCatalogProbe(consumerCatalogFixtures());

    expect($result['error'])->toBeNull()
        ->and($result['symbol'])->toBe(['nvl/comments', 'Nvl\\Comments\\Contracts\\ListOwnerCommentSummariesContract', 'interface', ['execute'], [], []])
        ->and($result['core_support'])->toBe('Nvl\\Support\\Contracts\\LocaleCatalog')
        ->and($result['core_data'])->toBe(['currentPage'])
        ->and($result['unknown'])->toBeNull()
        ->and($result['table'])->toBe('nvl/comments')
        ->and($result['internal_table'])->toBe('nvl/comments')
        ->and($result['missing_table'])->toBeNull()
        ->and($result['loaded'])->toBe([])
        ->and($result['fixture_loaded'])->toBeFalse();
});

it('retains immutable exact model permissions and internal ownership/trait metadata', function (): void {
    $result = consumerCatalogProbe(consumerCatalogFixtures());

    expect($result['error'])->toBeNull()
        ->and($result['model'])->toBe(['Nvl\\Comments\\Models\\Comment', ['id', 'revision'], ['getKey', 'getKeyName', 'getMorphClass', 'getRouteKey', 'getRouteKeyName', 'is', 'isNot', 'relationLoaded'], []])
        ->and($result['immutable'])->toBeTrue()
        ->and($result['traits'])->toBe(['comments'])
        ->and($result['unknown_trait'])->toBe([])
        ->and($result['owner'])->toBe('nvl/comments')
        ->and($result['host_owner'])->toBeNull()
        ->and(array_keys($result['core_roots']['psr4']))->toBe(['Nvl\\Support\\', 'Nvl\\Data\\']);
});

it('supports a symlinked Composer install path and skips only explicit workbench names and virtual packages', function (): void {
    $result = consumerCatalogProbe(consumerCatalogFixtures(), function (array $roots, array &$versions): void {
        symlink($roots['nvl/core'], $roots['nvl/core'].'-linked');
        $versions['nvl/core']['install_path'] = $roots['nvl/core'].'-linked';
        foreach (['nvl/suite', 'nvl/laravel-suite', 'nvl/suite-workbench'] as $name) {
            $versions[$name] = ['install_path' => dirname($roots['nvl/core']), 'type' => 'library'];
        }
        $versions['nvl/provided-only'] = ['provided' => ['*'], 'install_path' => null];
    });

    expect($result['error'])->toBeNull()
        ->and($result['core_roots']['path'])->not->toEndWith('-linked');
});

it('fails actionably when an actually installed NVL code library has no catalog', function (): void {
    $result = consumerCatalogProbe(consumerCatalogFixtures(), function (array $roots, array &$versions): void {
        $path = dirname($roots['nvl/core']).'/nvl-forms';
        mkdir($path);
        $versions['nvl/forms'] = ['install_path' => $path, 'type' => 'library'];
    });

    expect($result['exception'])->toBe('RuntimeException')
        ->and($result['error'])->toContain('nvl/forms', 'consumer-api.json', 'upgrade');
});

it('does not silently omit a code library whose Composer installation path is missing', function (): void {
    $result = consumerCatalogProbe(consumerCatalogFixtures(), function (array $roots, array &$versions): void {
        $versions['nvl/forms'] = ['install_path' => null, 'type' => 'library'];
    });

    expect($result['exception'] ?? null)->toBe('RuntimeException')
        ->and($result['error'])->toContain('nvl/forms', 'installation path');
});

it('rejects malformed protocol and permission metadata', function (Closure $mutate, string $reason): void {
    $catalogs = consumerCatalogFixtures();
    $mutate($catalogs['nvl/comments']);
    $result = consumerCatalogProbe($catalogs);

    expect($result['exception'])->toBe('RuntimeException')
        ->and($result['error'])->toContain('nvl/comments', $reason);
})->with([
    'missing version' => [function (array &$catalog): void {
        unset($catalog['schema_version']);
    }, 'schema_version'],
    'string version' => [function (array &$catalog): void {
        $catalog['schema_version'] = '1';
    }, 'schema_version'],
    'future version' => [function (array &$catalog): void {
        $catalog['schema_version'] = 2;
    }, 'schema_version'],
    'wrong package' => [function (array &$catalog): void {
        $catalog['package'] = 'nvl/forms';
    }, 'package'],
    'wrong symbol kind' => [function (array &$catalog): void {
        $catalog['symbols']['Nvl\\Comments\\Models\\Comment']['kind'] = 'object';
    }, 'kind'],
    'non-string member' => [function (array &$catalog): void {
        $catalog['symbols']['Nvl\\Comments\\Models\\Comment']['methods'] = [12];
    }, 'methods'],
    'duplicate member' => [function (array &$catalog): void {
        $catalog['symbols']['Nvl\\Comments\\Models\\Comment']['methods'] = ['save', 'save'];
    }, 'methods'],
    'unselected model' => [function (array &$catalog): void {
        $catalog['models']['Host\\Comment'] = $catalog['models']['Nvl\\Comments\\Models\\Comment'];
    }, 'model'],
    'write identity method' => [function (array &$catalog): void {
        $catalog['models']['Nvl\\Comments\\Models\\Comment']['identity_methods'][] = 'save';
    }, 'identity_methods'],
    'relation wildcard' => [function (array &$catalog): void {
        $catalog['capability_relations']['Nvl\\Comments\\Traits\\InteractsWithComments'] = ['*'];
    }, 'capability_relations'],
    'unselected capability trait' => [function (array &$catalog): void {
        $catalog['capability_relations']['Host\\HasComments'] = ['comments'];
    }, 'trait'],
    'source mismatch' => [function (array &$catalog): void {
        $catalog['symbols']['Nvl\\Comments\\Models\\Comment']['file'] = 'src/Models/Other.php';
    }, 'file'],
]);

it('retains a public alias shim and validates its forward canonical target without loading PHP', function (): void {
    $catalogs = consumerCatalogFixtures();
    $catalogs['nvl/comments']['symbols']['Nvl\\Comments\\Contracts\\LegacyLocaleCatalog'] = [
        'kind' => 'interface', 'file' => 'src/Contracts/LegacyLocaleCatalog.php', 'methods' => ['supported'], 'properties' => [], 'constants' => [], 'alias_of' => 'Nvl\\Support\\Contracts\\LocaleCatalog',
    ];
    $result = consumerCatalogProbe($catalogs);

    expect($result['error'])->toBeNull()
        ->and($result['alias'])->toBe('Nvl\\Support\\Contracts\\LocaleCatalog')
        ->and($result['loaded'])->toBe([]);
});

it('rejects unavailable, cyclic and incompatible public aliases', function (string $scenario): void {
    $catalogs = consumerCatalogFixtures();
    $alias = 'Nvl\\Comments\\Contracts\\LegacyLocaleCatalog';
    $catalogs['nvl/comments']['symbols'][$alias] = [
        'kind' => 'interface', 'file' => 'src/Contracts/LegacyLocaleCatalog.php', 'methods' => ['supported'], 'properties' => [], 'constants' => [], 'alias_of' => 'Nvl\\Support\\Contracts\\LocaleCatalog',
    ];
    $declaration = &$catalogs['nvl/comments']['symbols'][$alias];
    match ($scenario) {
        'missing' => $declaration['alias_of'] = 'Host\\Unknown',
        'invalid' => $declaration['alias_of'] = '*',
        'self' => $declaration['alias_of'] = $alias,
        'kind' => $declaration['kind'] = 'class',
        'surface' => $declaration['methods'] = ['supported', 'write'],
        'cycle' => $catalogs['nvl/core']['symbols']['Nvl\\Support\\Contracts\\LocaleCatalog']['alias_of'] = $alias,
    };
    $result = consumerCatalogProbe($catalogs);

    expect($result['exception'] ?? null)->toBe('RuntimeException')
        ->and($result['error'])->toContain('alias');
})->with(['missing', 'invalid', 'self', 'kind', 'surface', 'cycle']);

it('rejects conflicting symbol or table ownership across installed catalogs', function (bool $duplicateSymbol): void {
    $catalogs = consumerCatalogFixtures();
    $catalogs['nvl/forms'] = $catalogs['nvl/comments'];
    $catalogs['nvl/forms']['package'] = 'nvl/forms';
    if ($duplicateSymbol) {
        $catalogs['nvl/forms']['tables'] = [];
    } else {
        $catalogs['nvl/forms']['symbols'] = [];
        $catalogs['nvl/forms']['models'] = [];
        $catalogs['nvl/forms']['capability_relations'] = [];
    }
    $result = consumerCatalogProbe($catalogs);

    expect($result['exception'])->toBe('RuntimeException')
        ->and($result['error'])->toContain('ownership', $duplicateSymbol ? 'symbol' : 'table');
})->with([true, false]);

it('rejects escaping source and catalog symlinks', function (string $target): void {
    $result = consumerCatalogProbe(consumerCatalogFixtures(), function (array $roots) use ($target): void {
        $source = $target === 'source' ? $roots['nvl/comments'].'/src/Models/Comment.php' : $roots['nvl/comments'].'/resources/consumer-api.json';
        $outside = dirname($roots['nvl/comments']).'/outside-'.basename($source);
        rename($source, $outside);
        symlink($outside, $source);
    });

    expect($result['exception'])->toBe('RuntimeException')
        ->and($result['error'])->toContain('nvl/comments', 'outside');
})->with(['source', 'catalog']);

it('rejects a PSR-4 root that escapes the canonical package root', function (): void {
    $result = consumerCatalogProbe(consumerCatalogFixtures(), function (array $roots): void {
        $source = $roots['nvl/comments'].'/src';
        $outside = dirname($roots['nvl/comments']).'/outside-src';
        rename($source, $outside);
        symlink($outside, $source);
    });

    expect($result['exception'])->toBe('RuntimeException')
        ->and($result['error'])->toContain('nvl/comments', 'outside');
});

it('rejects a source symlink outside its declared PSR-4 root even inside the same package', function (): void {
    $result = consumerCatalogProbe(consumerCatalogFixtures(), function (array $roots): void {
        $source = $roots['nvl/comments'].'/src/Models/Comment.php';
        $outside = $roots['nvl/comments'].'/resources/Comment.php';
        rename($source, $outside);
        symlink($outside, $source);
    });

    expect($result['exception'] ?? null)->toBe('RuntimeException')
        ->and($result['error'])->toContain('PSR-4', 'outside');
});

it('marks only the reviewed Core roots and retains declared internal integration members', function (): void {
    $core = dirname(__DIR__, 3);
    $roots = [
        'data/src/Data/PaginatedCollection.php', 'data/src/Data/PaginationMeta.php',
        'data/src/Services/TypeScriptSourceRegistry.php', 'data/src/Traits/DataTransform.php',
        'support/src/Contracts/LocaleCatalog.php', 'support/src/Contracts/ResponseCode.php',
        'support/src/Doctor/DoctorCheck.php', 'support/src/Doctor/DoctorContributor.php',
        'support/src/Exceptions/BusinessException.php',
        'support/src/Owners/OwnerBatch.php', 'support/src/Owners/OwnerIdentity.php', 'support/src/Owners/OwnerResultMap.php',
        'support/src/Tenancy/Contracts/TenantBoundary.php', 'support/src/Tenancy/Contracts/TenantContext.php',
        'support/src/Tenancy/Contracts/TenantContextParticipant.php', 'support/src/Tenancy/Contracts/TenantDirectory.php',
        'support/src/Tenancy/Contracts/TenantHttpResolver.php', 'support/src/Tenancy/Contracts/TenantInstallationState.php',
        'support/src/Tenancy/Contracts/TenantMembershipAccess.php', 'support/src/Tenancy/Contracts/TenantOwnershipConfiguration.php',
        'support/src/Tenancy/Contracts/TenantQueueContext.php', 'support/src/Tenancy/Contracts/TenantQueueHandler.php',
        'support/src/Tenancy/Contracts/TenantQueuedJob.php', 'support/src/Tenancy/Contracts/TenantRunner.php',
        'support/src/Tenancy/Enums/TenantContextMode.php', 'support/src/Tenancy/Enums/TenantResourceKind.php', 'support/src/Tenancy/Enums/TenantStatus.php',
        'support/src/Tenancy/Services/TenantContextParticipants.php', 'support/src/Tenancy/Services/TenantResourceRegistry.php',
        'support/src/Tenancy/ValueObjects/PlatformOperation.php', 'support/src/Tenancy/ValueObjects/TenantContextSnapshot.php',
        'support/src/Tenancy/ValueObjects/TenantDescriptor.php', 'support/src/Tenancy/ValueObjects/TenantId.php',
        'support/src/Tenancy/ValueObjects/TenantJobEnvelope.php', 'support/src/Tenancy/ValueObjects/TenantResourceDefinition.php',
        'support/src/Tenancy/ValueObjects/TenantSiteContext.php',
    ];
    foreach ($roots as $path) {
        expect(file_get_contents($core.'/'.$path))->toMatch('~/\*\*(?:(?!\*/).)*@api\b(?:(?!\*/).)*\*/\s*(?:#\[[^\]]+\]\s*)*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s~s');
    }
    expect(file_get_contents($core.'/support/src/Tenancy/Contracts/TenantParentResolver.php'))->toContain('@internal')->not->toContain('@api')
        ->and(file_get_contents($core.'/data/src/Services/TypeScriptPathGuard.php'))->not->toContain('@api')
        ->and(file_get_contents($core.'/support/src/Tenancy/Services/TenantResourceRegistry.php'))->toContain('@internal');
});
