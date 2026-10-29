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

it('ignores historical queue and guard aliases', function (): void {
    config([
        'nvl-templates.queue.connection' => null,
        'nvl-templates.rendering.connection' => 'historical',
        'nvl-templates.auth.guard' => 'legacy-admin',
        'nvl-core.queue.connection' => 'native-jobs',
        'nvl-core.authorization.guard' => 'native-admin',
    ]);
    expect(PackageOptions::queueConnection('templates'))->toBe('native-jobs')
        ->and(PackageOptions::authGuard('templates'))->toBe('native-admin');
});

it('selects independent canonical media lock stores without historical aliases', function (): void {
    config([
        'nvl-media.locks' => ['store' => 'general', 'mutation' => ['store' => 'mutations'], 'deduplication' => ['store' => 'deduplication'], 'multipart' => ['store' => 'uploads']],
        'nvl-media.mutation_lock.store' => 'historical',
    ]);
    expect(PackageOptions::lockStore('media', 'mutation'))->toBe('mutations')
        ->and(PackageOptions::lockStore('media', 'deduplication'))->toBe('deduplication')
        ->and(PackageOptions::lockStore('media', 'multipart'))->toBe('uploads');
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

it('preserves canonical infrastructure through a configuration cache round trip', function (): void {
    config(['nvl-templates.queue.connection' => 'native-jobs']);
    $cache = tempnam(sys_get_temp_dir(), 'nvl-options-');
    expect($cache)->not->toBeFalse();
    file_put_contents($cache, '<?php return '.var_export(config()->all(), true).';');
    try {
        config()->set(require $cache);
        expect(PackageOptions::queueConnection('templates'))->toBe('native-jobs');
    } finally {
        unlink($cache);
    }
});

it('does not create deprecation reports while reading canonical settings', function (): void {
    PackageOptions::queueConnection('templates');
    expect(config('nvl-core.configuration.deprecations'))->toBeNull();
});

it('inherits Core for canonical null even when a historical override is present', function (): void {
    config([
        'nvl-templates.queue.connection' => null,
        'nvl-templates.rendering.connection' => 'historical',
        'nvl-core.queue.connection' => 'suite-jobs',
    ]);
    expect(PackageOptions::queueConnection('templates'))->toBe('suite-jobs');
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
