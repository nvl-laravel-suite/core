<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Nvl\Support\Adoption\AdoptionCoordinator;
use Nvl\Support\Adoption\AdoptionRegistry;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Tests\Fixtures\FakeAdoptionAdapter;
use Nvl\Support\Tests\SupportedSchemaDatabaseTestCase;
use Orchestra\Testbench\Foundation\Application;

require dirname(__DIR__, 6).'/vendor/autoload.php';

$source = json_decode($argv[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
$options = json_decode($argv[2] ?? '', true, flags: JSON_THROW_ON_ERROR);
if (! is_array($source) || ($source['driver'] ?? null) !== 'pgsql' || ! is_string($source['database'] ?? null)
    || ! is_array($options) || ! is_string($options['directory'] ?? null) || ! is_dir($options['directory'])
    || preg_match('/^nvl-adoption-race-[a-f0-9]{16}$/D', basename($options['directory'])) !== 1
    || ! in_array($options['index'] ?? null, [0, 1], true)
    || ! is_string($options['fingerprint'] ?? null) || ! is_string($options['run'] ?? null)) {
    throw new RuntimeException('Adoption race workers require the disposable PostgreSQL Core database and barriers.');
}
SupportedSchemaDatabaseTestCase::validateDatabase($source['driver'], $source['database']);

$app = Application::create(options: ['load_environment_variables' => false, 'extra' => [
    'dont-discover' => ['*'],
    'providers' => [LocaleServiceProvider::class, SupportServiceProvider::class],
]]);
$app->make(Kernel::class)->bootstrap();
$app['config']->set('database.connections.adoption_race', $source);
$app['config']->set('database.default', 'adoption_race');
$app->make(AdoptionRegistry::class)->register('fixture', FakeAdoptionAdapter::class);
$connection = $app->make('db')->connection();
$backend = $connection->selectOne('select pg_backend_pid() as pid, current_database() as database');
if (! is_object($backend) || $backend->database !== $source['database']) {
    throw new RuntimeException('The adoption race worker connected to an unexpected database.');
}

$ready = $options['directory'].'/ready-'.$options['index'];
file_put_contents($ready.'.pending', json_encode(['backend' => (int) $backend->pid, 'process' => getmypid()], JSON_THROW_ON_ERROR));
rename($ready.'.pending', $ready);
$deadline = microtime(true) + 10;
while (! is_file($options['directory'].'/release-'.$options['index'])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('The adoption race release barrier timed out.');
    }
    usleep(5000);
    clearstatcache();
}

if ($options['index'] === 0) {
    FakeAdoptionAdapter::$pauseFile = $options['directory'].'/locked-0';
    FakeAdoptionAdapter::$continueFile = $options['directory'].'/continue-0';
}
$progress = (new AdoptionCoordinator($app->make(AdoptionRegistry::class)->make('fixture', SystemActor::Background)))
    ->apply($options['fingerprint'], 1, $options['run']);
fwrite(STDOUT, json_encode(['written' => $progress->written, 'writes' => FakeAdoptionAdapter::$writes, 'state' => $progress->state], JSON_THROW_ON_ERROR));
