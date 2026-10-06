<?php

declare(strict_types=1);
use Illuminate\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Support\Locales\ApplicationLocaleCatalog;
use Nvl\Support\Providers\LocaleServiceProvider;

require $argv[1].'/autoload.php';
$root = $argv[2];
$mode = $argv[3];
$app = Application::configure(basePath: $root)->withExceptions()->create();
$host = new ApplicationLocaleCatalog(new Repository(['app' => ['locale' => 'fr', 'fallback_locale' => 'fr']]));
if ($mode === 'early') {
    $app->instance(LocaleCatalog::class, $host);
}
try {
    $app->make(Kernel::class)->bootstrap();
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(1);
}
$deferred = $app->isDeferredService(LocaleCatalog::class);
$loaded = $app->getProvider(LocaleServiceProvider::class) !== null;
if ($mode === 'late') {
    $app->instance(LocaleCatalog::class, $host);
}

$first = $app->make(LocaleCatalog::class);
$second = $app->make(LocaleCatalog::class);
if ($mode === 'write-config') {
    file_put_contents($app->getCachedConfigPath(), '<?php return '.var_export($app['config']->all(), true).';');
}
echo json_encode(['deferred' => $deferred, 'loaded_before' => $loaded, 'same' => $first === $second, 'host' => $first === $host, 'locale' => $first->default(), 'cached' => $app->configurationIsCached(), 'provider' => $app->getProvider(LocaleServiceProvider::class) !== null], JSON_THROW_ON_ERROR);
