<?php

declare(strict_types=1);

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Support\Facades\Artisan;
use Nvl\Support\Providers\SupportServiceProvider;

it('registers exactly the adoption and raw quarantine retry commands in the console', function (): void {
    $commands = array_values(array_filter(array_keys(Artisan::all()), static fn (string $name): bool => str_starts_with($name, 'nvl:')));
    sort($commands);

    expect($commands)->toBe(['nvl:adopt', 'nvl:queue:retry']);
});

it('registers no console commands outside the console', function (): void {
    $bootstrappers = new ReflectionProperty(ConsoleApplication::class, 'bootstrappers');
    $console = new ReflectionProperty($this->app, 'isRunningInConsole');
    $before = $bootstrappers->getValue();

    try {
        $console->setValue($this->app, false);
        $this->app->call([new SupportServiceProvider($this->app), 'boot']);
        $outside = $bootstrappers->getValue();
        $console->setValue($this->app, true);
        $this->app->call([new SupportServiceProvider($this->app), 'boot']);
        $inside = $bootstrappers->getValue();
    } finally {
        $console->setValue($this->app, true);
        $bootstrappers->setValue(null, $before);
    }

    expect($outside)->toHaveCount(count($before))
        ->and($inside)->toHaveCount(count($before) + 1);
});
