<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Unit;

use Closure;
use PHPUnit\Runner\CodeCoverage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SplFileInfo;
use stdClass;
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
            'models' => new stdClass,
            'capability_relations' => new stdClass,
            'tables' => new stdClass,
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
 * Split one prefix's selected classes across two ordered runtime directories.
 *
 * @return array<string, array<string, mixed>>
 */
function consumerMultiRootFixtures(): array
{
    $catalogs = consumerCatalogFixtures();
    $catalogs['nvl/comments']['psr4']['Nvl\\Comments\\'] = ['src/', 'contracts-src/'];
    $catalogs['nvl/comments']['symbols']['Nvl\\Comments\\Contracts\\ListOwnerCommentSummariesContract']['file'] = 'contracts-src/Contracts/ListOwnerCommentSummariesContract.php';

    return $catalogs;
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

            foreach ($catalog['psr4'] ?? [] as $directories) {
                foreach (is_string($directories) ? [$directories] : $directories as $relative) {
                    if (! is_dir($root.'/'.$relative)) {
                        mkdir($root.'/'.$relative, 0700, true);
                    }
                }
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
if (function_exists('pcov\\start')) { pcov\start(); } elseif (function_exists('xdebug_info') && in_array('coverage', xdebug_info('mode'), true)) { xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE); }
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
        'trait_methods' => $catalog->symbol('Nvl\\Comments\\Traits\\InteractsWithComments')?->methods,
        'unknown_trait' => $catalog->capabilityRelations('Host\\HasComments'),
        'owner' => $comment === null ? null : $catalog->packageForFile($comment->sourcePath()),
        'contract_owner' => $symbol === null ? null : $catalog->packageForFile($symbol->sourcePath()),
        'host_owner' => $catalog->packageForFile($argv[2].'/host/Nvl/Comments/Models/Comment.php'),
        'core_roots' => $core,
        'leaf_roots' => $roots['nvl/comments'] ?? null,
        'immutable' => $immutable,
        'loaded' => array_values(array_filter(get_declared_classes(), static fn (string $class): bool => str_starts_with($class, 'Illuminate\\') || str_starts_with($class, 'Nvl\\Suite\\') || str_starts_with($class, 'PhpParser\\'))),
        'fixture_loaded' => class_exists('Nvl\\Comments\\Models\\Comment', false),
        'alias' => $catalog->symbol('Nvl\\Comments\\Contracts\\LegacyLocaleCatalog')?->aliasOf ?? null,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['error' => $exception->getMessage(), 'exception' => $exception::class], JSON_THROW_ON_ERROR);
}
if (function_exists('pcov\\collect')) { pcov\stop(); file_put_contents($argv[2].'/coverage.json', json_encode(pcov\collect(), JSON_THROW_ON_ERROR)); } elseif (function_exists('xdebug_info') && in_array('coverage', xdebug_info('mode'), true)) { file_put_contents($argv[2].'/coverage.json', json_encode(xdebug_get_code_coverage(), JSON_THROW_ON_ERROR)); }
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, $repository, $directory]);
        $process->mustRun();

        /** @var array<string, mixed> $result */
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        if (CodeCoverage::instance()->isActive() && is_file($directory.'/coverage.json')) {
            $coverage = json_decode(file_get_contents($directory.'/coverage.json'), true, flags: JSON_THROW_ON_ERROR);
            $lines = [];
            foreach (is_array($coverage) ? $coverage : [] as $file => $counts) {
                if (! is_string($file) || ! is_array($counts)) {
                    continue;
                }
                foreach ($counts as $line => $count) {
                    if (is_int($line) && is_int($count)) {
                        $lines[$file][$line] = $count;
                    }
                }
            }
            CodeCoverage::instance()->codeCoverage()->append(RawCodeCoverageData::fromXdebugWithoutPathCoverage($lines));
        }

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

it('retains forbidden capability relations when the selected trait has no public methods without loading source PHP', function (): void {
    $catalogs = consumerCatalogFixtures();
    $catalogs['nvl/comments']['symbols']['Nvl\\Comments\\Traits\\InteractsWithComments']['methods'] = [];
    $result = consumerCatalogProbe($catalogs);

    expect($result['error'])->toBeNull()
        ->and($result['traits'])->toBe(['comments'])
        ->and($result['trait_methods'])->toBe([])
        ->and($result['loaded'])->toBe([])
        ->and($result['fixture_loaded'])->toBeFalse();
});

it('supports ordered multi-root prefixes alongside existing string roots and symlinked installations', function (bool $symlinked): void {
    $result = consumerCatalogProbe(consumerMultiRootFixtures(), function (array $roots, array &$versions) use ($symlinked): void {
        if ($symlinked) {
            $link = $roots['nvl/comments'].'-linked';
            symlink($roots['nvl/comments'], $link);
            $versions['nvl/comments']['install_path'] = $link;
        }
    });

    expect($result['error'])->toBeNull()
        ->and($result['owner'])->toBe('nvl/comments')
        ->and($result['contract_owner'])->toBe('nvl/comments')
        ->and($result['leaf_roots']['psr4']['Nvl\\Comments\\'])->toBe([$result['leaf_roots']['path'].'/src', $result['leaf_roots']['path'].'/contracts-src'])
        ->and($result['core_roots']['psr4']['Nvl\\Support\\'])->toBe([$result['core_roots']['path'].'/support/src'])
        ->and($result['core_roots']['psr4']['Nvl\\Data\\'])->toBe([$result['core_roots']['path'].'/data/src'])
        ->and($result['loaded'])->toBe([])
        ->and($result['fixture_loaded'])->toBeFalse();
})->with([false, true]);

it('rejects invalid multi-root lists and duplicate root identities', function (string $scenario, string $reason): void {
    $result = consumerCatalogProbe(consumerCatalogFixtures(), function (array $roots) use ($scenario): void {
        $path = $roots['nvl/comments'].'/resources/consumer-api.json';
        $catalog = json_decode(file_get_contents($path), flags: JSON_THROW_ON_ERROR);
        $prefix = 'Nvl\\Comments\\';
        $catalog->psr4->{$prefix} = match ($scenario) {
            'missing' => null,
            'empty' => [],
            'object' => new stdClass,
            'number' => ['src/', 12],
            'nested' => ['src/', ['nested/']],
            'non-list' => (object) ['first' => 'src/'],
            'missing directory' => ['src/', 'missing/'],
            'escaping' => ['src/', '../outside/'],
            'escaping symlink' => ['src/', 'escape-src/'],
            'duplicate' => ['src/', 'src/'],
            'duplicate spelling' => ['src/', 'src'],
            'duplicate canonical' => ['src/', 'alias-src/'],
            'duplicate prefix root' => 'src/',
        };
        if ($scenario === 'duplicate canonical') {
            symlink($roots['nvl/comments'].'/src', $roots['nvl/comments'].'/alias-src');
        }
        if ($scenario === 'escaping symlink') {
            $outside = dirname($roots['nvl/comments']).'/outside-src';
            mkdir($outside);
            symlink($outside, $roots['nvl/comments'].'/escape-src');
        }
        if ($scenario === 'duplicate prefix root') {
            $catalog->psr4->{'Nvl\\Comments\\Other\\'} = 'src/';
        }
        file_put_contents($path, json_encode($catalog, JSON_THROW_ON_ERROR));
    });

    expect($result['exception'] ?? null)->toBe('RuntimeException')
        ->and($result['error'])->toContain($reason);
})->with([
    'missing value' => ['missing', 'nonempty list'],
    'empty list' => ['empty', 'nonempty list'],
    'object value' => ['object', 'nonempty list'],
    'non-string root' => ['number', 'relative source directory'],
    'nested list' => ['nested', 'relative source directory'],
    'non-list value' => ['non-list', 'nonempty list'],
    'missing directory' => ['missing directory', 'unavailable'],
    'escaping directory' => ['escaping', 'nonescaping'],
    'escaping unselected root symlink' => ['escaping symlink', 'outside its installed package'],
    'duplicate relative root' => ['duplicate', 'Duplicate'],
    'duplicate normalized relative root' => ['duplicate spelling', 'Duplicate'],
    'duplicate canonical root' => ['duplicate canonical', 'Duplicate'],
    'duplicate prefix root' => ['duplicate prefix root', 'Duplicate'],
]);

it('matches the longest prefix to its specific ordered source root', function (bool $correct): void {
    $catalogs = consumerMultiRootFixtures();
    $catalogs['nvl/comments']['psr4']['Nvl\\Comments\\Models\\'] = ['models-src/', 'alternate-models/'];
    if ($correct) {
        $catalogs['nvl/comments']['symbols']['Nvl\\Comments\\Models\\Comment']['file'] = 'alternate-models/Comment.php';
    }
    $result = consumerCatalogProbe($catalogs);

    if ($correct) {
        expect($result['error'])->toBeNull()
            ->and($result['owner'])->toBe('nvl/comments')
            ->and($result['loaded'])->toBe([]);
    } else {
        expect($result['exception'] ?? null)->toBe('RuntimeException')
            ->and($result['error'])->toContain('file does not match');
    }
})->with([true, false]);

it('rejects a selected declaration duplicated across ordered source roots', function (): void {
    $result = consumerCatalogProbe(consumerMultiRootFixtures(), function (array $roots): void {
        $other = $roots['nvl/comments'].'/contracts-src/Models/Comment.php';
        mkdir(dirname($other), 0700, true);
        copy($roots['nvl/comments'].'/src/Models/Comment.php', $other);
    });

    expect($result['exception'] ?? null)->toBe('RuntimeException')
        ->and($result['error'])->toContain('Duplicate', 'Nvl\\Comments\\Models\\Comment');
});

it('rejects a source symlink into another listed root instead of its matching root', function (): void {
    $result = consumerCatalogProbe(consumerMultiRootFixtures(), function (array $roots): void {
        $source = $roots['nvl/comments'].'/src/Models/Comment.php';
        $other = $roots['nvl/comments'].'/contracts-src/rogue.php';
        rename($source, $other);
        symlink($other, $source);
    });

    expect($result['exception'] ?? null)->toBe('RuntimeException')
        ->and($result['error'])->toContain('outside its declared PSR-4 source root');
});

it('rejects a symbol file outside every matching prefix root', function (): void {
    $catalogs = consumerMultiRootFixtures();
    $catalogs['nvl/comments']['symbols']['Nvl\\Comments\\Models\\Comment']['file'] = 'unlisted/Models/Comment.php';
    $result = consumerCatalogProbe($catalogs);

    expect($result['exception'] ?? null)->toBe('RuntimeException')
        ->and($result['error'])->toContain('file does not match');
});

it('rejects overlapping ownership from any root of independently installed packages', function (): void {
    $result = consumerCatalogProbe(consumerMultiRootFixtures(), function (array $roots, array &$versions): void {
        $nested = $roots['nvl/comments'].'/contracts-src/nested';
        mkdir($nested.'/src', 0700, true);
        mkdir($nested.'/resources', 0700, true);
        $catalog = ['schema_version' => 1, 'package' => 'nvl/forms', 'psr4' => ['Nvl\\Forms\\' => ['src/']], 'symbols' => new stdClass, 'models' => new stdClass, 'capability_relations' => new stdClass, 'tables' => new stdClass];
        file_put_contents($nested.'/resources/consumer-api.json', json_encode($catalog, JSON_THROW_ON_ERROR));
        $versions['nvl/forms'] = ['install_path' => $nested, 'type' => 'library'];
    });

    expect($result['exception'] ?? null)->toBe('RuntimeException')
        ->and($result['error'])->toContain('Conflicting source ownership', 'nvl/comments');
});

it('keeps empty JSON objects distinct from lists at every catalog boundary', function (string $field, bool $objectInsteadOfList): void {
    $result = consumerCatalogProbe(consumerCatalogFixtures(), function (array $roots) use ($field, $objectInsteadOfList): void {
        $path = $roots['nvl/comments'].'/resources/consumer-api.json';
        $catalog = json_decode(file_get_contents($path), flags: JSON_THROW_ON_ERROR);
        $value = $objectInsteadOfList ? new stdClass : [];
        $symbol = 'Nvl\\Comments\\Models\\Comment';
        $trait = 'Nvl\\Comments\\Traits\\InteractsWithComments';
        match ($field) {
            'catalog' => $catalog = $value,
            'symbol' => $catalog->symbols->{$symbol} = $value,
            'model' => $catalog->models->{$symbol} = $value,
            'methods', 'properties', 'constants' => $catalog->symbols->{$symbol}->{$field} = $value,
            'read', 'identity_methods', 'model relations' => $catalog->models->{$symbol}->{$field === 'model relations' ? 'capability_relations' : $field} = $value,
            'trait relations' => $catalog->capability_relations->{$trait} = $value,
            default => $catalog->{$field} = $value,
        };
        if ($field === 'symbols') {
            $catalog->models = new stdClass;
            $catalog->capability_relations = new stdClass;
        }
        file_put_contents($path, json_encode($catalog, JSON_THROW_ON_ERROR));
    });

    expect($result['exception'] ?? null)->toBe('RuntimeException')
        ->and($result['error'])->toContain($objectInsteadOfList ? 'must be a list of exact names' : 'must be an object map');
})->with([
    'catalog list' => ['catalog', false],
    'psr4 list' => ['psr4', false],
    'symbols list' => ['symbols', false],
    'models list' => ['models', false],
    'tables list' => ['tables', false],
    'capability map list' => ['capability_relations', false],
    'symbol declaration list' => ['symbol', false],
    'model declaration list' => ['model', false],
    'methods object' => ['methods', true],
    'properties object' => ['properties', true],
    'constants object' => ['constants', true],
    'read object' => ['read', true],
    'identity methods object' => ['identity_methods', true],
    'model relations object' => ['model relations', true],
    'trait relations object' => ['trait relations', true],
]);

it('accepts empty permission lists and empty object maps without executing source PHP', function (): void {
    $catalogs = consumerCatalogFixtures();
    $symbol = 'Nvl\\Comments\\Models\\Comment';
    foreach (['methods', 'properties', 'constants'] as $field) {
        $catalogs['nvl/comments']['symbols'][$symbol][$field] = [];
    }
    $catalogs['nvl/comments']['models'][$symbol] = ['read' => [], 'identity_methods' => [], 'capability_relations' => []];
    $catalogs['nvl/comments']['capability_relations'] = new stdClass;
    $result = consumerCatalogProbe($catalogs);

    expect($result['error'])->toBeNull()
        ->and($result['model'])->toBe([$symbol, [], [], []])
        ->and($result['loaded'])->toBe([])
        ->and($result['fixture_loaded'])->toBeFalse();
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
    'malformed relation name' => [function (array &$catalog): void {
        $catalog['capability_relations']['Nvl\\Comments\\Traits\\InteractsWithComments'] = ['comments.extra'];
    }, 'capability_relations'],
    'duplicate relation name' => [function (array &$catalog): void {
        $catalog['capability_relations']['Nvl\\Comments\\Traits\\InteractsWithComments'] = ['comments', 'comments'];
    }, 'capability_relations'],
    'nontrait capability symbol' => [function (array &$catalog): void {
        $catalog['capability_relations']['Nvl\\Comments\\Models\\Comment'] = ['comments'];
    }, 'trait'],
    'unselected capability trait' => [function (array &$catalog): void {
        $catalog['capability_relations']['Host\\HasComments'] = ['comments'];
    }, 'trait'],
    'source mismatch' => [function (array &$catalog): void {
        $catalog['symbols']['Nvl\\Comments\\Models\\Comment']['file'] = 'src/Models/Other.php';
    }, 'file'],
]);

it('rejects capability metadata that claims a trait selected by another package', function (): void {
    $catalogs = consumerCatalogFixtures();
    $trait = 'Nvl\\Support\\Traits\\HasComments';
    $catalogs['nvl/core']['symbols'][$trait] = [
        'kind' => 'trait', 'file' => 'support/src/Traits/HasComments.php', 'methods' => [], 'properties' => [], 'constants' => [],
    ];
    $catalogs['nvl/comments']['capability_relations'][$trait] = ['comments'];
    $result = consumerCatalogProbe($catalogs);

    expect($result['exception'])->toBe('RuntimeException')
        ->and($result['error'])->toContain('nvl/comments', $trait, 'selected trait in this package');
});

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
        $catalogs['nvl/forms']['tables'] = new stdClass;
    } else {
        $catalogs['nvl/forms']['symbols'] = new stdClass;
        $catalogs['nvl/forms']['models'] = new stdClass;
        $catalogs['nvl/forms']['capability_relations'] = new stdClass;
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
        'support/src/Exceptions/BusinessException.php', 'support/src/Exceptions/SupportException.php',
        'support/src/Tenancy/Exceptions/TenantNotFound.php',
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
        ->and(file_get_contents($core.'/support/src/Tenancy/Exceptions/TenancyException.php'))->toContain('@api')
        ->and(file_get_contents($core.'/support/src/Tenancy/Enums/TenancyResponseCode.php'))->toContain('@api')
        ->and(file_get_contents($core.'/support/src/Tenancy/Services/TenantResourceRegistry.php'))->toContain('@internal');
});
