<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Nvl\Support\Consumer\PHPStan\ConsumerBoundaryPolicy;
use PHPStan\Reflection\ReflectionProvider;

it('rejects invalid exception identities and unsafe path forms', function (array $changes): void {
    $exception = array_replace([
        'file' => dirname(__DIR__).'/PHPStan/Fixtures/adapter.php.stub',
        'identifier' => 'nvl.consumer.packageQuery',
        'symbol' => 'Nvl\\Comments\\Models\\Comment::where',
        'reason' => 'Reviewed C1 adapter predicate.',
    ], $changes);

    expect(fn () => new ConsumerBoundaryPolicy(Mockery::mock(ReflectionProvider::class)->shouldIgnoreMissing(), dirname(__DIR__, 6), [], [], [$exception]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty reason' => [['reason' => ' ']],
    'wildcard symbol' => [['symbol' => 'Nvl\\Comments\\Models\\Comment::*']],
    'wildcard file' => [['file' => 'app/*.php']],
    'missing file' => [['file' => '/tmp/nvl-boundary-file-does-not-exist.php']],
    'path traversal' => [['file' => '../host.php']],
    'unknown identifier' => [['identifier' => 'nvl.consumer.unknown']],
    'unowned symbol' => [['symbol' => 'Host\\Other::where']],
    'unowned table' => [['identifier' => 'nvl.consumer.ownedTable', 'symbol' => 'host_table']],
]);

it('validates additional physical table ownership without replacing defaults', function (): void {
    $reflection = Mockery::mock(ReflectionProvider::class)->shouldIgnoreMissing();
    $policy = new ConsumerBoundaryPolicy($reflection, dirname(__DIR__, 6), [], ['tenant_comments' => 'nvl/comments']);

    expect($policy->table('tenant_comments as c'))->toBe('tenant_comments')
        ->and($policy->table('nvl_comments_comments'))->toBe('nvl_comments_comments')
        ->and($policy->table('host_articles'))->toBeNull();
    expect(fn () => new ConsumerBoundaryPolicy($reflection, dirname(__DIR__, 6), [], ['nvl_comments_comments' => 'nvl/core']))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects implicit missing test roots', function (): void {
    expect(fn () => new ConsumerBoundaryPolicy(Mockery::mock(ReflectionProvider::class), dirname(__DIR__, 6), ['app/Tests/absent']))
        ->toThrow(InvalidArgumentException::class);
});

it('hashes installed catalogs and normalized host options for stable result-cache invalidation', function (): void {
    $reflection = Mockery::mock(ReflectionProvider::class)->shouldIgnoreMissing();
    $base = dirname(__DIR__, 6);
    $first = new ConsumerBoundaryPolicy($reflection, $base, [], ['tenant_comments' => 'nvl/comments', 'alternate_comments' => 'nvl/comments']);
    $ordered = new ConsumerBoundaryPolicy($reflection, $base, [], ['alternate_comments' => 'nvl/comments', 'tenant_comments' => 'nvl/comments']);
    $changed = new ConsumerBoundaryPolicy($reflection, $base, [], ['alternate_comments' => 'nvl/comments']);
    expect($first->getKey())->toBe('nvl.consumer.boundary')
        ->and($first->getHash())->toBe($ordered->getHash())
        ->and($first->getHash())->not->toBe($changed->getHash());
});

it('invalidates cached analysis when installed host subclass permissions change', function (): void {
    $root = sys_get_temp_dir().'/nvl-consumer-cache-'.bin2hex(random_bytes(8));
    mkdir($root.'/src', 0700, true);
    mkdir($root.'/resources', 0700, true);
    $file = $root.'/resources/consumer-api.json';
    $class = 'ConsumerCacheFixture\\Principal';
    file_put_contents($root.'/src/Principal.php', '<?php throw new LogicException("Cache discovery must not execute source.");');
    $catalog = [
        'schema_version' => 1, 'package' => 'nvl/consumer-cache-fixture', 'psr4' => ['ConsumerCacheFixture\\' => 'src/'],
        'symbols' => [$class => ['kind' => 'class', 'file' => 'src/Principal.php', 'methods' => [], 'properties' => [], 'constants' => []]],
        'models' => [$class => ['read' => [], 'identity_methods' => [], 'capability_relations' => []]],
        'capability_relations' => new stdClass, 'tables' => new stdClass,
    ];
    $original = InstalledVersions::getAllRawData()[0];
    $installed = $original;
    $installed['versions']['nvl/consumer-cache-fixture'] = ['install_path' => $root, 'version' => '6.0.0', 'reference' => 'fixture'];
    file_put_contents($file, json_encode($catalog, JSON_THROW_ON_ERROR));
    InstalledVersions::reload($installed);
    try {
        $policy = new ConsumerBoundaryPolicy(Mockery::mock(ReflectionProvider::class), dirname(__DIR__, 6));
        $before = $policy->getHash();
        $catalog['models'][$class]['host_subclasses'] = true;
        $catalog['models'][$class]['capability_methods'] = ['assignRole'];
        file_put_contents($file, json_encode($catalog, JSON_THROW_ON_ERROR));

        $metadataChanged = $policy->getHash();
        expect($metadataChanged)->not->toBe($before)
            ->and(class_exists($class, false))->toBeFalse();
        $installed['versions']['acme/principals'] = ['install_path' => $root.'/src', 'version' => '1.0.0', 'reference' => 'foreign-first'];
        InstalledVersions::reload($installed);
        $foreignInstalled = $policy->getHash();
        expect($foreignInstalled)->not->toBe($metadataChanged);
        $installed['versions']['acme/principals']['reference'] = 'foreign-upgraded';
        InstalledVersions::reload($installed);
        expect($policy->getHash())->not->toBe($foreignInstalled);
    } finally {
        InstalledVersions::reload($original);
        unlink($file);
        unlink($root.'/src/Principal.php');
        rmdir($root.'/resources');
        rmdir($root.'/src');
        rmdir($root);
    }
});
