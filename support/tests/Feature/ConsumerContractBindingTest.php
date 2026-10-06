<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Tests\TestCase;

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses(TestCase::class);
}

test('Core retains a shared owner registry and accepts late host replacement', function (): void {
    $first = $this->app->make(OwnerRegistry::class);
    expect($this->app->make(OwnerRegistry::class))->toBe($first);
    $host = new OwnerRegistry(new Repository(['nvl-core' => ['owners' => []]]));
    $this->app->instance(OwnerRegistry::class, $host);
    expect($this->app->make(OwnerRegistry::class))->toBe($host);
});

test('Core provider preserves an early host registry across independent application lifecycles', function (): void {
    $registries = [];
    try {
        foreach ([1, 2] as $lifecycle) {
            $consumer = new Application($this->app->basePath());
            $config = new Repository($this->app->make('config')->all());
            $consumer->instance('config', $config);
            $consumer->instance('env', 'testing');
            $consumer->register(FilesystemServiceProvider::class);
            $host = new OwnerRegistry($config);
            $consumer->instance(OwnerRegistry::class, $host);
            $consumer->register(SupportServiceProvider::class);
            expect($consumer->make(OwnerRegistry::class))->toBe($host);
            $registries[$lifecycle] = $host;
            $consumer->flush();
        }
        expect($registries[1])->not->toBe($registries[2]);
    } finally {
        Container::setInstance($this->app);
    }
});
