<?php

declare(strict_types=1);

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
