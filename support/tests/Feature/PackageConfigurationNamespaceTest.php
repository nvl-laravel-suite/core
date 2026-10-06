<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Nvl\Support\Config\PackageConfiguration;
use Nvl\Support\Config\PackageEnvironment;
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

it('requires explicit compatibility and preserves intentional canonical values', function (): void {
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

it('rejects unknown legacy paths instead of copying foreign options', function (): void {
    config(['media' => ['queue_name' => 'foreign'], 'nvl-core.compatibility.legacy_config' => ['media']]);
    expect(fn () => mergeNvlConfigurationFixture('media'))->toThrow(InvalidArgumentException::class);
});

it('normalizes logical and canonical names without changing resource identities', function (): void {
    expect(class_exists(PackageConfiguration::class))->toBeTrue();
    expect(PackageConfiguration::key('media'))->toBe('nvl-media')
        ->and(PackageConfiguration::key('nvl-media'))->toBe('nvl-media')
        ->and(PackageConfiguration::logical('nvl-media'))->toBe('media')
        ->and(PackageConfiguration::key('nvl-data'))->toBe('nvl-data');
});

it('isolates legacy env variables and preserves a canonical false value', function (): void {
    expect(class_exists(PackageEnvironment::class))->toBeTrue();
    putenv('MEDIA_QUEUE=foreign-work');
    putenv('NVL_MEDIA_QUEUE=false');
    try {
        expect(PackageEnvironment::get('NVL_MEDIA_QUEUE', 'default'))->toBeFalse();
        putenv('NVL_MEDIA_QUEUE');
        expect(PackageEnvironment::get('NVL_MEDIA_QUEUE', 'default'))->toBe('default');
        config(['nvl-core.compatibility.legacy_env' => true]);
        expect(PackageEnvironment::get('NVL_MEDIA_QUEUE', 'default'))->toBe('foreign-work');
    } finally {
        putenv('MEDIA_QUEUE');
        putenv('NVL_MEDIA_QUEUE');
    }
});

it('detects legacy NVL config only through Doctor without merging it', function (): void {
    config(['media' => ['owner_slots' => ['idempotency' => ['enabled' => true]], 'deduplication_lock' => ['seconds' => 60]]]);
    $before = config('media');
    $checks = app(CoreDoctor::class)->inspect();
    $keys = array_map(static fn ($check): string => $check->key, $checks);
    expect($keys)->toContain('configuration.legacy.media')
        ->and(config('media'))->toBe($before);
});

it('does not diagnose generic foreign shapes or malformed lookalike markers', function (): void {
    config([
        'primitives' => ['money' => ['currency' => 'USD']],
        'taxonomy' => ['tree' => []],
        'media' => ['owner_slots' => 'host-library'],
        'payments' => ['stripe' => ['key' => 'host-owned']],
        'pages' => ['urls' => []],
    ]);
    expect(PackageConfiguration::detectedLegacy(config()))->toBe([]);
});

it('recognizes shipped NVL option fingerprints for every formerly generic config file', function (string $package): void {
    $path = dirname(__DIR__, 4).'/'.$package.'/config/nvl-'.$package.'.php';
    $legacy = require $path;
    config([$package => $legacy]);
    expect(PackageConfiguration::detectedLegacy(config()))->toHaveKey($package)
        ->and(config($package))->toBe($legacy);
})->with(['activity', 'billing', 'comments', 'content', 'forms', 'mail-notifications', 'media', 'metafields', 'pages', 'payments', 'primitives', 'seo', 'settings', 'tasks', 'taxonomy', 'templates', 'tenancy', 'translatable', 'translations']);

it('preserves canonical null and empty env values', function (string $raw, mixed $expected): void {
    putenv('MEDIA_QUEUE=foreign-work');
    putenv('NVL_MEDIA_QUEUE='.$raw);
    try {
        config(['nvl-core.compatibility.legacy_env' => true]);
        expect(PackageEnvironment::get('NVL_MEDIA_QUEUE', 'default'))->toBe($expected);
    } finally {
        putenv('MEDIA_QUEUE');
        putenv('NVL_MEDIA_QUEUE');
    }
})->with([['null', null], ['empty', '']]);

it('uses the reviewed inventory for nonstandard legacy env names', function (): void {
    putenv('MAIL_BRAND_NAME=Legacy brand');
    try {
        config(['nvl-core.compatibility.legacy_env' => true]);
        expect(PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_BRAND_NAME', 'default'))->toBe('Legacy brand')
            ->and(config('nvl-core.configuration.legacy_env.MAIL_BRAND_NAME'))->toBe('NVL_MAIL_NOTIFICATIONS_BRAND_NAME');
    } finally {
        putenv('MAIL_BRAND_NAME');
    }
});

it('honors explicit published env compatibility before Core config is loaded', function (): void {
    $original = app()->configPath();
    $directory = sys_get_temp_dir().'/nvl-env-config-'.bin2hex(random_bytes(8));
    mkdir($directory);
    file_put_contents($directory.'/nvl-core.php', '<?php return ["compatibility" => ["legacy_env" => true]];');
    app()->useConfigPath($directory);
    $core = config('nvl-core');
    unset($core['compatibility']['legacy_env']);
    config(['nvl-core' => $core]);
    putenv('COMMENTS_METADATA_DIGEST_KEY=legacy-digest');
    try {
        expect(PackageEnvironment::get('NVL_COMMENTS_METADATA_DIGEST_KEY'))->toBe('legacy-digest');
    } finally {
        putenv('COMMENTS_METADATA_DIGEST_KEY');
        app()->useConfigPath($original);
        unlink($directory.'/nvl-core.php');
        rmdir($directory);
    }
});

it('does not remerge generic roots or env inputs into cached config', function (): void {
    $foreign = ['queue_name' => 'spatie-work'];
    config(['media' => $foreign, 'nvl-media' => ['enabled' => true], 'nvl-core.compatibility.legacy_config' => ['media']]);
    app()->instance('config_loaded_from_cache', true);
    mergeNvlConfigurationFixture('media');
    expect(config('nvl-media'))->toBe(['enabled' => true])
        ->and(config('media'))->toBe($foreign)
        ->and(config('nvl-core.configuration.legacy_reads.media'))->toBeNull();
});

it('accepts recognized historical option shapes only under explicit compatibility', function (): void {
    config([
        'nvl-core.compatibility.legacy_config' => ['media'],
        'media' => ['storage' => ['connection' => 'historical'], 'mutation_lock' => ['store' => 'mutations']],
    ]);
    mergeNvlConfigurationFixture('media');
    expect(config('nvl-media.connection'))->toBe('historical')
        ->and(config('nvl-media.locks.mutation.store'))->toBe('mutations')
        ->and(config('media.storage.connection'))->toBe('historical');
});
