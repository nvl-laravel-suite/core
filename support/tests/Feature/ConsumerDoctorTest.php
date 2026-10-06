<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Nvl\Support\Doctor\DoctorCheck;
use Nvl\Support\Doctor\DoctorContributor;
use Nvl\Support\Doctor\DoctorRegistry;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Providers\DoctorServiceProvider;

beforeEach(function (): void {
    $this->app->register(DoctorServiceProvider::class);
});

it('reports an empty consumer installation without workbench providers', function (): void {
    expect(Artisan::call('nvl:doctor', ['--strict' => true, '--format' => 'json']))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report)->toMatchArray([
        'schema_version' => 1,
        'healthy' => true,
        'strict' => true,
    ])->and(array_unique(array_column($report['checks'], 'package')))->toBe(['nvl/core'])
        ->and($report['checks'])->toHaveCount(5);
});

it('discovers only contributors registered by loaded providers and sorts the report', function (): void {
    $this->app->register(new class($this->app) extends ServiceProvider
    {
        public function register(): void
        {
            PackageDoctorContributor::register($this->app, 'nvl/zebra', static fn (): array => [
                ['key' => 'z', 'severity' => 'error', 'passed' => true, 'message' => 'Z ready.'],
                ['key' => 'a', 'severity' => 'info', 'passed' => true, 'message' => 'Optional adapter is unavailable.'],
            ]);
            PackageDoctorContributor::register($this->app, 'nvl/alpha', static fn (): array => [
                ['key' => 'ready', 'severity' => 'error', 'passed' => true, 'message' => 'Alpha ready.'],
            ]);
        }
    });

    $registry = $this->app->make(DoctorRegistry::class);
    $first = $registry->inspect(true);

    $domainChecks = array_values(array_filter($first['checks'], static fn (array $check): bool => $check['package'] !== 'nvl/core'));
    expect($registry->inspect(true))->toBe($first)
        ->and(array_column($domainChecks, 'package'))->toBe(['nvl/alpha', 'nvl/zebra', 'nvl/zebra'])
        ->and(array_column($domainChecks, 'key'))->toBe(['ready', 'a', 'z']);
});

it('fails errors and strict warnings while informational omissions remain healthy', function (string $severity, bool $strict, int $exitCode): void {
    PackageDoctorContributor::register($this->app, 'nvl/example', static fn (): array => [
        new DoctorCheck('optional.adapter', $severity, false, 'Install the configured adapter or disable the capability.'),
    ]);

    expect(Artisan::call('nvl:doctor', ['--strict' => $strict, '--format' => 'json']))->toBe($exitCode)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['healthy'])->toBe($exitCode === 0);
})->with([
    'ordinary warning' => ['warning', false, 0],
    'strict warning' => ['warning', true, 1],
    'error' => ['error', false, 1],
    'informational omission' => ['info', true, 0],
]);

it('rejects unknown output formats before contributor execution', function (): void {
    PackageDoctorContributor::register($this->app, 'nvl/example', static function (): array {
        throw new RuntimeException('This contributor must not execute.');
    });

    expect(Artisan::call('nvl:doctor', ['--format' => 'yaml']))->not->toBe(0)
        ->and(Artisan::output())->toContain('text or json')
        ->not->toContain('This contributor');
});

it('records contributor failures and continues inspecting other packages', function (): void {
    PackageDoctorContributor::register($this->app, 'nvl/broken', static function (): array {
        throw new RuntimeException('The selected connection is unavailable.');
    });
    PackageDoctorContributor::register($this->app, 'nvl/healthy', static fn (): array => [
        new DoctorCheck('ready', 'error', true, 'Ready.'),
    ]);

    expect(Artisan::call('nvl:doctor', ['--format' => 'json']))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['checks'][0])->toMatchArray([
        'package' => 'nvl/broken',
        'key' => 'contributor.execution',
        'severity' => 'error',
        'result' => 'fail',
    ])->and($report['checks'][0]['message'])->toContain('selected connection')
        ->and(array_column($report['checks'], 'package'))->toContain('nvl/healthy');
});

it('reports resolution failures from a tagged contributor instead of a healthy empty report', function (): void {
    $this->app->bind('broken.doctor', static function (): never {
        throw new RuntimeException('Contributor cannot be constructed.');
    });
    $this->app->tag('broken.doctor', DoctorContributor::class);

    expect(Artisan::call('nvl:doctor', ['--format' => 'json']))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['healthy'])->toBeFalse();
});

it('adapts package-owned object checks without changing their results', function (): void {
    $check = (object) ['key' => 'ready', 'severity' => 'warning', 'passed' => false, 'message' => 'Configure readiness.'];
    $contributor = new PackageDoctorContributor('nvl/example', static fn (): array => [$check]);

    expect(iterator_to_array($contributor->inspect()))->toEqual([
        new DoctorCheck('ready', 'warning', false, 'Configure readiness.'),
    ])->and($check->severity)->toBe('warning');
});

it('reports legacy locale configuration through the shared strict gate', function (): void {
    config(['app.locale' => 'en', 'app.fallback_locale' => 'en', 'nvl-primitives.locales.supported' => ['en']]);

    expect(Artisan::call('nvl:doctor', ['--strict' => true, '--format' => 'json']))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($report['checks'], 'severity'))->toContain('warning')
        ->and(implode(' ', array_column($report['checks'], 'message')))->toContain('primitives.locales');
});

it('reports cached infrastructure deprecations once without disclosing host values', function (): void {
    config(['nvl-core.configuration.deprecations' => [
        'nvl-media.mutation_lock.store' => ['replacement' => 'nvl-media.locks.mutation.store', 'value' => 'secret-host-value', 'conflict' => true],
    ]]);

    expect(Artisan::call('nvl:doctor', ['--strict' => true, '--format' => 'json']))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $checks = array_values(array_filter($report['checks'], static fn (array $check): bool => str_starts_with($check['key'], 'configuration.deprecated.')));

    expect($checks)->toHaveCount(1)
        ->and($checks[0])->toMatchArray(['severity' => 'warning', 'result' => 'fail'])
        ->and($checks[0]['message'])->toContain('media.locks.mutation.store')
        ->not->toContain('secret-host-value');
});

it('fails unconfigured or malformed shared infrastructure without domain contributors', function (string $option, mixed $value): void {
    config(['nvl-core.'.$option => $value]);

    expect(Artisan::call('nvl:doctor', ['--format' => 'json']))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $checks = array_column($report['checks'], null, 'key');

    expect($checks['configuration.'.$option])->toMatchArray([
        'package' => 'nvl/core', 'severity' => 'error', 'result' => 'fail',
    ])->and($checks)->not->toHaveKey('contributor.execution')
        ->and($checks)->toHaveKeys(['configuration.connection', 'configuration.queue.connection', 'configuration.queue.name', 'configuration.locks.store', 'configuration.authorization.guard']);
})->with([
    'missing database backend' => ['connection', 'missing-database'],
    'missing queue backend' => ['queue.connection', 'missing-queue'],
    'empty queue name' => ['queue.name', ''],
    'missing cache backend' => ['locks.store', 'missing-cache'],
    'missing auth guard' => ['authorization.guard', 'missing-guard'],
    'malformed queue name' => ['queue.connection', []],
    'malformed database name' => ['connection', false],
]);

it('reports only effective infrastructure names and preserves an explicit sync queue', function (): void {
    config(['nvl-core.queue.connection' => 'sync', 'nvl-core.queue.name' => 'shared-work', 'nvl-core.connection' => null, 'nvl-core.locks.store' => null, 'nvl-core.authorization.guard' => null]);

    expect(Artisan::call('nvl:doctor', ['--strict' => true, '--format' => 'json']))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $checks = array_column($report['checks'], null, 'key');

    expect($checks['configuration.queue.connection'])->toMatchArray(['severity' => 'info', 'result' => 'pass'])
        ->and($checks['configuration.queue.connection']['message'])->toContain('[sync]')
        ->and($checks['configuration.queue.name']['message'])->toContain('[shared-work]');
});

it('diagnoses disabled native quarantine persistence without opening a connection', function (mixed $driver): void {
    config(['queue.failed.driver' => $driver, 'database.default' => 'unavailable']);
    expect(Artisan::call('nvl:doctor', ['--strict' => true, '--format' => 'json']))->toBe(1);
    $checks = array_column(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'], null, 'key');
    expect($checks['queue.quarantine.persistence'])->toMatchArray(['severity' => 'warning', 'result' => 'fail'])
        ->and($checks['queue.quarantine.persistence']['message'])->toContain('deleted safely', 'raw retry');
})->with(['native null' => null, 'null driver' => 'null']);

it('reports an unavailable native failed-job store for an asynchronous queue', function (): void {
    config(['nvl-core.queue.connection' => 'database', 'queue.failed.driver' => 'database-uuids', 'queue.failed.table' => 'missing_native_failed_jobs']);
    expect(Artisan::call('nvl:doctor', ['--format' => 'json']))->toBe(1);
    $checks = array_column(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'], null, 'key');
    expect($checks['queue.quarantine.persistence'])->toMatchArray(['severity' => 'error', 'result' => 'fail'])
        ->and($checks['queue.quarantine.persistence']['message'])->toContain('missing_native_failed_jobs');
});
