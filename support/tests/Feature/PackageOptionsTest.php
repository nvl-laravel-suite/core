<?php

declare(strict_types=1);

use Nvl\Support\Config\PackageOptions;

it('inherits effective infrastructure through package Core and Laravel configuration', function (): void {
    config([
        'nvl-media.queue' => ['connection' => null, 'name' => null],
        'nvl-media.locks.store' => null,
        'nvl-media.authorization.guard' => null,
        'nvl-core.queue' => ['connection' => 'suite-jobs', 'name' => 'suite-work'],
        'nvl-core.locks.store' => 'suite-cache',
        'nvl-core.authorization.guard' => 'admin',
        'queue.default' => 'application-jobs',
        'cache.default' => 'application-cache',
        'auth.defaults.guard' => 'web',
    ]);

    expect(PackageOptions::queueConnection('media'))->toBe('suite-jobs')
        ->and(PackageOptions::queueName('media'))->toBe('suite-work')
        ->and(PackageOptions::lockStore('media'))->toBe('suite-cache')
        ->and(PackageOptions::authGuard('media'))->toBe('admin');

    config([
        'nvl-core.queue' => ['connection' => null, 'name' => null],
        'nvl-core.locks.store' => null,
        'nvl-core.authorization.guard' => null,
        'queue.connections.application-jobs.queue' => 'application-work',
    ]);

    expect(PackageOptions::queueConnection('media'))->toBe('application-jobs')
        ->and(PackageOptions::queueName('media'))->toBe('application-work')
        ->and(PackageOptions::lockStore('media'))->toBe('application-cache')
        ->and(PackageOptions::authGuard('media'))->toBe('web');
});

it('preserves package and operation overrides without changing lock lifetimes', function (): void {
    config([
        'nvl-media.queue' => ['connection' => 'media-jobs', 'name' => 'media-work'],
        'nvl-media.locks' => ['store' => 'media-cache', 'multipart' => ['store' => 'uploads']],
        'nvl-media.multipart.lock.seconds' => 125,
        'nvl-media.authorization.guard' => 'media-users',
        'nvl-core.queue' => ['connection' => 'suite-jobs', 'name' => 'suite-work'],
        'nvl-core.locks.store' => 'suite-cache',
    ]);

    expect(PackageOptions::queueConnection('media'))->toBe('media-jobs')
        ->and(PackageOptions::queueName('media'))->toBe('media-work')
        ->and(PackageOptions::lockStore('media'))->toBe('media-cache')
        ->and(PackageOptions::lockStore('media', 'multipart'))->toBe('uploads')
        ->and(PackageOptions::authGuard('media'))->toBe('media-users')
        ->and(config('nvl-media.multipart.lock.seconds'))->toBe(125);
});

it('inherits named database connections while preserving migration opt-in defaults', function (): void {
    config([
        'nvl-billing.connection' => null,
        'nvl-core.connection' => null,
        'database.default' => 'host-database',
        'nvl-billing.migrations.enabled' => false,
    ]);

    expect(PackageOptions::connection('billing'))->toBe('host-database')
        ->and(PackageOptions::migrationsEnabled('billing'))->toBeFalse()
        ->and(PackageOptions::migrationsEnabled('payments', false))->toBeFalse();
    config(['nvl-core.connection' => 'suite-storage', 'nvl-billing.connection' => 'billing-storage']);
    expect(PackageOptions::connection('billing'))->toBe('billing-storage');
});

it('normalizes only declared package aliases and reports canonical conflicts once', function (): void {
    $normalized = PackageOptions::normalize('templates', [
        'queue' => ['connection' => 'canonical'],
        'rendering' => ['connection' => 'historical', 'queue' => 'render-work', 'timeout' => 90],
        'auth' => ['guard' => 'legacy-admin'],
    ]);
    PackageOptions::normalize('templates', $normalized);

    expect($normalized['queue'])->toBe(['connection' => 'canonical', 'name' => 'render-work'])
        ->and($normalized['authorization']['guard'])->toBe('legacy-admin')
        ->and($normalized['rendering']['timeout'])->toBe(90)
        ->and(PackageOptions::deprecations('templates'))->toHaveCount(3)
        ->and(PackageOptions::deprecations('templates')['nvl-templates.rendering.connection'])
        ->toBe(['replacement' => 'nvl-templates.queue.connection', 'value' => 'canonical', 'conflict' => true]);

    expect(PackageOptions::normalize('content', ['rendering' => ['connection' => 'unrelated']], false))
        ->toBe(['rendering' => ['connection' => 'unrelated']]);
});

it('retains independent historical media lock stores instead of collapsing them', function (): void {
    $normalized = PackageOptions::normalize('media', [
        'mutation_lock' => ['store' => 'mutations', 'seconds' => 70],
        'deduplication_lock' => ['store' => 'deduplication'],
        'multipart' => ['lock' => ['store' => 'multipart']],
    ]);
    config(['nvl-media' => $normalized]);

    expect(PackageOptions::lockStore('media', 'mutation'))->toBe('mutations')
        ->and(PackageOptions::lockStore('media', 'deduplication'))->toBe('deduplication')
        ->and(PackageOptions::lockStore('media', 'multipart'))->toBe('multipart')
        ->and($normalized['mutation_lock']['seconds'])->toBe(70);
});

it('inherits route lists atomically and applies a selected authentication guard', function (): void {
    config([
        'nvl-content.routes.management.middleware' => null,
        'nvl-content.routes.middleware' => null,
        'nvl-core.routes.middleware' => ['api', 'auth', 'throttle:90,1'],
        'nvl-core.authorization.guard' => 'admin',
    ]);

    expect(PackageOptions::routeMiddleware('content', 'management'))->toBe(['api', 'auth:admin', 'throttle:90,1']);

    config(['nvl-content.routes.management.middleware' => []]);
    expect(PackageOptions::routeMiddleware('content', 'management'))->toBe([]);
    config(['nvl-content.routes.management.middleware' => ['auth:token']]);
    expect(PackageOptions::routeMiddleware('content', 'management'))->toBe(['auth:token']);
});

it('preserves normalized values and deprecations through a configuration cache round trip', function (): void {
    config(['nvl-templates' => PackageOptions::normalize('templates', ['rendering' => ['connection' => 'historical']])]);
    $cache = tempnam(sys_get_temp_dir(), 'nvl-options-');
    expect($cache)->not->toBeFalse();
    file_put_contents($cache, '<?php return '.var_export(config()->all(), true).';');

    try {
        $cached = require $cache;
        config()->set($cached);
        expect(PackageOptions::queueConnection('templates'))->toBe('historical')
            ->and(PackageOptions::deprecations('templates'))->toHaveKey('nvl-templates.rendering.connection');
    } finally {
        unlink($cache);
    }
});

it('does not report aliases from shipped defaults', function (): void {
    PackageOptions::normalize('templates', ['rendering' => ['connection' => null, 'queue' => null]], false);
    expect(PackageOptions::deprecations('templates'))->toBe([]);
});

it('inherits Core when an explicit canonical null accompanies a historical override', function (): void {
    $host = PackageOptions::normalize('templates', [
        'queue' => ['connection' => null],
        'rendering' => ['connection' => 'historical'],
    ]);
    config([
        'nvl-templates' => $host,
        'nvl-core.options_explicit.templates' => ['queue.connection'],
        'nvl-core.queue.connection' => 'suite-jobs',
    ]);

    expect($host['queue']['connection'])->toBeNull()
        ->and(PackageOptions::queueConnection('templates'))->toBe('suite-jobs');
});

it('rejects invalid names instead of silently inheriting them', function (string $key, mixed $value, string $method): void {
    config([$key => $value]);
    expect(fn () => PackageOptions::{$method}('media'))->toThrow(InvalidArgumentException::class);
})->with([
    'empty canonical connection' => ['nvl-media.queue.connection', '', 'queueConnection'],
    'invalid Core queue' => ['nvl-core.queue.name', 12, 'queueName'],
    'empty lock store' => ['nvl-media.locks.store', ' ', 'lockStore'],
    'invalid guard' => ['nvl-media.authorization.guard', [], 'authGuard'],
]);
