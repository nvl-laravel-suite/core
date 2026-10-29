<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Nvl\Support\Config\PackageConfiguration;
use Nvl\Support\Doctor\CoreDoctor;
use Nvl\Support\Traits\MergesPackageConfiguration;

/** Merge a small real package configuration through its provider boundary. */
function mergeNvlConfigurationFixture(string $package): void
{
    $path = tempnam(sys_get_temp_dir(), 'nvl-config-');
    file_put_contents($path, '<?php return ["enabled" => false, "queue" => ["name" => null], "custom" => ["items" => ["default"]]];');
    $provider = new class(app()) extends ServiceProvider
    {
        use MergesPackageConfiguration;

        /** Load the fixture using the same configuration seam as packages. */
        public function loadFixture(string $path, string $package): void
        {
            $this->mergePackageConfiguration($path, $package);
        }
    };
    try {
        $provider->loadFixture($path, $package);
    } finally {
        unlink($path);
    }
}

it('preserves foreign configuration and writes only canonical package roots', function (string $package, array $foreign): void {
    config([$package => $foreign]);
    mergeNvlConfigurationFixture($package);
    expect(config($package))->toBe($foreign)
        ->and(config('nvl-'.$package.'.enabled'))->toBeFalse();
})->with([
    ['settings', []],
    ['tenancy', ['central_domains' => ['host.test']]],
    ['translatable', ['locales' => ['el', 'en']]],
    ['media', ['queue_name' => 'spatie-media']],
]);

it('ignores retired compatibility switches and preserves intentional canonical values', function (): void {
    $legacy = ['enabled' => true, 'queue' => ['name' => 'old-work'], 'custom' => ['items' => ['old']]];
    config([
        'media' => $legacy,
        'nvl-core.compatibility.legacy_config' => ['media'],
        'nvl-media' => ['enabled' => false, 'queue' => ['name' => null], 'custom' => ['items' => []]],
    ]);
    mergeNvlConfigurationFixture('media');
    expect(config('media'))->toBe($legacy)
        ->and(config('nvl-media.enabled'))->toBeFalse()
        ->and(config('nvl-media.queue.name'))->toBeNull()
        ->and(config('nvl-media.custom.items'))->toBe([]);
});

it('never copies foreign options even when retired compatibility is selected', function (): void {
    config(['media' => ['queue_name' => 'foreign'], 'nvl-core.compatibility.legacy_config' => ['media']]);
    mergeNvlConfigurationFixture('media');
    expect(config('nvl-media.queue.name'))->toBeNull()
        ->and(config('media.queue_name'))->toBe('foreign');
});

it('normalizes logical and canonical names without changing resource identities', function (): void {
    expect(class_exists(PackageConfiguration::class))->toBeTrue();
    expect(PackageConfiguration::key('media'))->toBe('nvl-media')
        ->and(PackageConfiguration::key('nvl-media'))->toBe('nvl-media')
        ->and(PackageConfiguration::logical('nvl-media'))->toBe('media')
        ->and(PackageConfiguration::key('nvl-data'))->toBe('nvl-data');
});

it('reads canonical environment values without legacy fallbacks', function (string $raw, mixed $expected): void {
    putenv('MEDIA_QUEUE=foreign-work');
    putenv('NVL_MEDIA_QUEUE='.$raw);
    try {
        config(['nvl-core.compatibility.legacy_env' => true]);
        expect(env('NVL_MEDIA_QUEUE', 'default'))->toBe($expected);
        putenv('NVL_MEDIA_QUEUE');
        expect(env('NVL_MEDIA_QUEUE', 'default'))->toBe('default');
    } finally {
        putenv('MEDIA_QUEUE');
        putenv('NVL_MEDIA_QUEUE');
    }
})->with([['false', false], ['null', null], ['empty', ''], ['native-work', 'native-work']]);

it('does not inspect foreign package-shaped configuration in native diagnostics', function (): void {
    config(['media' => ['owner_slots' => ['idempotency' => ['enabled' => true]], 'deduplication_lock' => ['seconds' => 60]]]);
    $before = config('media');
    $keys = array_map(static fn ($check): string => $check->key, app(CoreDoctor::class)->inspect());
    expect($keys)->not->toContain('configuration.legacy.media')
        ->and(config('media'))->toBe($before);
});

it('merges canonical defaults recursively while replacing lists atomically', function (): void {
    config(['nvl-media' => ['enabled' => true, 'custom' => ['items' => []]]]);
    mergeNvlConfigurationFixture('media');
    expect(config('nvl-media.enabled'))->toBeTrue()
        ->and(config('nvl-media.queue.name'))->toBeNull()
        ->and(config('nvl-media.custom.items'))->toBe([]);
});

it('uses canonical package defaults without manufacturing legacy metadata', function (string $package): void {
    mergeNvlConfigurationFixture($package);
    expect(config('nvl-'.$package.'.enabled'))->toBeFalse()
        ->and(config('nvl-core.configuration.legacy_reads'))->toBeNull()
        ->and(config('nvl-core.storage_explicit'))->toBeNull()
        ->and(config('nvl-core.options_explicit'))->toBeNull();
})->with(['activity', 'billing', 'comments', 'content', 'forms', 'mail-notifications', 'media', 'metafields', 'pages', 'payments', 'primitives', 'seo', 'settings', 'tasks', 'taxonomy', 'templates', 'translatable', 'translations']);

it('does not remerge generic roots or env inputs into cached config', function (): void {
    $foreign = ['queue_name' => 'spatie-work'];
    config(['media' => $foreign, 'nvl-media' => ['enabled' => true], 'nvl-core.compatibility.legacy_config' => ['media']]);
    app()->instance('config_loaded_from_cache', true);
    mergeNvlConfigurationFixture('media');
    expect(config('nvl-media'))->toBe(['enabled' => true])
        ->and(config('media'))->toBe($foreign)
        ->and(config('nvl-core.configuration.legacy_reads.media'))->toBeNull();
});

it('does not translate historical storage or lock option shapes', function (): void {
    config([
        'nvl-core.compatibility.legacy_config' => ['media'],
        'media' => ['storage' => ['connection' => 'historical'], 'mutation_lock' => ['store' => 'mutations']],
    ]);
    mergeNvlConfigurationFixture('media');
    expect(config('nvl-media.connection'))->toBeNull()
        ->and(config('nvl-media.locks.mutation.store'))->toBeNull()
        ->and(config('media.storage.connection'))->toBe('historical');
});
