<?php

declare(strict_types=1);

use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Bus\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Support\Enums\PublicActor;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Providers\DoctorServiceProvider;
use Nvl\Support\Providers\TenantServiceProvider;
use Nvl\Support\Tests\Fixtures\OwnershipCapableModel;
use Nvl\Support\Tests\Fixtures\OwnershipDefinitionCompany;
use Symfony\Component\Process\Process;

it('boots core without activating retired or optional infrastructure', function (): void {
    $providers = $this->app->getLoadedProviders();

    expect($providers)->not->toHaveKey(TenantServiceProvider::class)
        ->not->toHaveKey(DoctorServiceProvider::class)
        ->not->toHaveKey(DataServiceProvider::class)
        ->and($this->app->make('router')->getRoutes()->count())->toBe(0);
});

it('publishes separate explicit background and anonymous actor identities', function (): void {
    expect(SystemActor::cases())->toBe([SystemActor::Background])
        ->and(PublicActor::cases())->toBe([PublicActor::Anonymous])
        ->and(SystemActor::Background)->not->toBe(PublicActor::Anonymous);
});

it('leaves native bus and batch services intact without the ownership runtime', function (): void {
    expect($this->app->make(Dispatcher::class)::class)->toBe(Dispatcher::class)
        ->and($this->app->make(BatchRepository::class)::class)->toBe(DatabaseBatchRepository::class);
});

it('denies declared ownership before accessing storage without its enforcing runtime', function (): void {
    $this->app->make(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition(
        'test.products',
        'test',
        OwnershipCapableModel::class,
        OwnershipDefinition::column(OwnershipDefinitionCompany::class, 'company_id'),
    ));
    $connection = (new OwnershipCapableModel)->getConnection();
    $connection->enableQueryLog();

    expect(fn () => $this->app->make(OwnershipBoundary::class)->query(OwnershipCapableModel::query(), 'test.products'))
        ->toThrow(OwnershipViolation::class);
    expect($connection->getQueryLog())->toBe([]);
});

it('boots and caches a native core application without loading optional namespaces', function (): void {
    $filesystem = new Filesystem;
    $source = dirname(__DIR__, 6);
    $workspace = sys_get_temp_dir().'/nvl-light-core-'.bin2hex(random_bytes(8));
    $filesystem->makeDirectory($workspace.'/bootstrap/cache', 0755, true);
    $filesystem->makeDirectory($workspace.'/storage/framework/views', 0755, true);
    $filesystem->makeDirectory($workspace.'/config');
    symlink($source.'/vendor', $workspace.'/vendor');
    $filesystem->put($workspace.'/composer.json', json_encode(['extra' => ['laravel' => ['dont-discover' => ['*']]]], JSON_THROW_ON_ERROR));
    $filesystem->put($workspace.'/bootstrap/app.php', '<?php return Illuminate\\Foundation\\Application::configure(basePath: dirname(__DIR__))->withProviders([Nvl\\Support\\Providers\\SupportServiceProvider::class])->withExceptions()->create();');
    $script = <<<'PHP'
        require $argv[1].'/vendor/autoload.php';
        spl_autoload_register(static function (string $class): void {
            foreach (['Nvl\\Data\\', 'Nvl\\Tenancy\\', 'Nvl\\Support\\Tenancy\\', 'Nvl\\Ownership\\', 'Spatie\\LaravelData\\', 'Spatie\\TypeScriptTransformer\\', 'Spatie\\LaravelTypeScriptTransformer\\'] as $prefix) {
                if (str_starts_with($class, $prefix)) {
                    throw new LogicException('Core loaded an unavailable dependency: '.$class);
                }
            }
        }, true, true);
        $app = require $argv[2].'/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        if ($kernel->call('config:cache') !== 0 || $kernel->call('route:cache') !== 0) {
            throw new RuntimeException('Native Core caches could not be built.');
        }
        echo json_encode([
            'bus' => $app->make(Illuminate\Bus\Dispatcher::class)::class,
            'connections' => count($app->make('db')->getConnections()),
            'package_routes' => count(array_filter($app->make('router')->getRoutes()->getRoutes(), static fn ($route): bool => str_starts_with($route->getActionName(), 'Nvl\\'))),
        ], JSON_THROW_ON_ERROR);
        PHP;
    try {
        $process = new Process([PHP_BINARY, '-r', $script, $source, $workspace], $workspace, [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => $workspace.'/bootstrap/cache/config.php',
            'APP_SERVICES_CACHE' => $workspace.'/bootstrap/cache/services.php',
            'APP_PACKAGES_CACHE' => $workspace.'/bootstrap/cache/packages.php',
            'APP_ROUTES_CACHE' => $workspace.'/bootstrap/cache/routes.php',
        ]);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($result)->toBe(['bus' => Dispatcher::class, 'connections' => 0, 'package_routes' => 0]);
    } finally {
        $filesystem->deleteDirectory($workspace);
    }
});
