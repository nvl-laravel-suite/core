<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Nvl\Support\Config\PackageConfigurationMerger;
use Nvl\Support\Tests\Fixtures\ConfigurationServiceProvider;

it('recursively merges maps while replacing lists and type changes atomically', function (): void {
    $defaults = [
        'nested' => [
            'kept' => true,
            'changed' => 'default',
            'list' => ['api', 'auth', 'throttle'],
            'emptyable' => ['first', 'second'],
            'deeper' => ['kept' => 1, 'changed' => 2],
        ],
        'class' => stdClass::class,
        'scalar' => 'default',
        'nullable' => 'default',
        'becomes_map' => 'default',
        'numeric_map' => [2 => 'two', 4 => 'four'],
    ];
    $host = [
        'nested' => [
            'changed' => 'host',
            'list' => ['web'],
            'emptyable' => [],
            'deeper' => ['changed' => 3],
        ],
        'class' => RuntimeException::class,
        'scalar' => 42,
        'nullable' => null,
        'becomes_map' => ['enabled' => true],
        'numeric_map' => [4 => 'changed', 6 => 'six'],
    ];
    $originalDefaults = $defaults;
    $originalHost = $host;

    $merged = PackageConfigurationMerger::merge($defaults, $host);

    expect($merged)->toBe([
        'nested' => [
            'kept' => true,
            'changed' => 'host',
            'list' => ['web'],
            'emptyable' => [],
            'deeper' => ['kept' => 1, 'changed' => 3],
        ],
        'class' => RuntimeException::class,
        'scalar' => 42,
        'nullable' => null,
        'becomes_map' => ['enabled' => true],
        'numeric_map' => [2 => 'two', 4 => 'changed', 6 => 'six'],
    ])->and($defaults)->toBe($originalDefaults)
        ->and($host)->toBe($originalHost);
});

it('treats a list on either side as an atomic host replacement', function (): void {
    expect(PackageConfigurationMerger::merge(
        ['value' => ['first', 'second', 'third']],
        ['value' => ['replacement']],
    ))->toBe(['value' => ['replacement']])
        ->and(PackageConfigurationMerger::merge(
            ['value' => ['first' => true]],
            ['value' => ['replacement']],
        ))->toBe(['value' => ['replacement']])
        ->and(PackageConfigurationMerger::merge(
            ['value' => ['first', 'second']],
            ['value' => ['replacement' => true]],
        ))->toBe(['value' => ['replacement' => true]]);
});

it('loads defaults for absent host config and applies exact list overrides', function (): void {
    $path = sys_get_temp_dir().'/nvl-support-config-'.bin2hex(random_bytes(4)).'.php';
    file_put_contents($path, <<<'PHP'
<?php

return [
    'routes' => [
        'middleware' => ['api', 'auth', 'throttle'],
        'prefix' => 'api/v1',
    ],
];
PHP);

    try {
        $application = new Application;
        $configuration = new Repository;
        $application->instance('config', $configuration);
        $application->instance('files', new Filesystem);
        $provider = new ConfigurationServiceProvider($application);

        $provider->mergeConfiguration($path, 'example');

        expect($configuration->get('example'))->toBe([
            'routes' => [
                'middleware' => ['api', 'auth', 'throttle'],
                'prefix' => 'api/v1',
            ],
        ]);

        $configuration->set('example', [
            'routes' => ['middleware' => ['web']],
        ]);
        $provider->mergeConfiguration($path, 'example');

        expect($configuration->get('example'))->toBe([
            'routes' => [
                'middleware' => ['web'],
                'prefix' => 'api/v1',
            ],
        ]);
    } finally {
        @unlink($path);
    }
});

it('does not merge package sources while configuration is cached', function (): void {
    $basePath = sys_get_temp_dir().'/nvl-support-cached-'.bin2hex(random_bytes(4));
    mkdir($basePath.'/bootstrap/cache', recursive: true);
    file_put_contents($basePath.'/bootstrap/cache/config.php', '<?php return [];');

    try {
        $application = new Application($basePath);
        $provider = new ConfigurationServiceProvider($application);

        $provider->mergeConfiguration('/source/must/not/be/read.php', 'example');

        expect($application->configurationIsCached())->toBeTrue();
    } finally {
        @unlink($basePath.'/bootstrap/cache/config.php');
        @rmdir($basePath.'/bootstrap/cache');
        @rmdir($basePath.'/bootstrap');
        @rmdir($basePath);
    }
});
