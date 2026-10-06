<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('discovers the Core locale provider natively and preserves deferred resolution with cached config and host bindings', function (): void {
    $vendor = dirname((new ReflectionClass(ClassLoader::class))->getFileName(), 2);
    $root = sys_get_temp_dir().'/nvl-e-discovery-'.bin2hex(random_bytes(8));
    mkdir($root.'/bootstrap/cache', 0755, true);
    mkdir($root.'/config');
    mkdir($root.'/app');
    symlink($vendor, $root.'/vendor');
    $installed = json_decode(file_get_contents($vendor.'/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
    $ignore = array_values(array_filter(array_column($installed['packages'], 'name'), fn ($name) => $name !== 'nvl/core'));
    file_put_contents($root.'/composer.json', json_encode(['extra' => ['laravel' => ['dont-discover' => $ignore]]], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/config/app.php', '<?php return ["name" => "Native consumer", "env" => "testing", "key" => "base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=", "locale" => "en", "fallback_locale" => "en"];');
    $run = function (string $mode) use ($vendor, $root): array {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/runtime-experience/discovered-locale.php', $vendor, $root, $mode], $root, ['APP_CONFIG_CACHE' => $root.'/bootstrap/cache/config.php', 'APP_PACKAGES_CACHE' => $root.'/bootstrap/cache/packages.php', 'APP_SERVICES_CACHE' => $root.'/bootstrap/cache/services.php', 'APP_ROUTES_CACHE' => $root.'/bootstrap/cache/routes.php']);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    };
    try {
        $cold = $run('cold');
        expect($cold)->toBe(['deferred' => true, 'loaded_before' => false, 'same' => true, 'host' => false, 'locale' => 'en', 'cached' => false, 'provider' => true]);
        foreach (['early', 'late'] as $mode) {
            $host = $run($mode);
            expect($host['loaded_before'])->toBeFalse()->and($host['host'])->toBeTrue()->and($host['locale'])->toBe('fr');
        }
        $run('write-config');
        $warm = $run('warm');
        expect($warm['cached'])->toBeTrue()->and($warm['loaded_before'])->toBeFalse()->and($warm['deferred'])->toBeTrue()->and($warm['same'])->toBeTrue();
    } finally {
        unlink($root.'/vendor');
        (new Filesystem)->deleteDirectory($root);
    }
});
