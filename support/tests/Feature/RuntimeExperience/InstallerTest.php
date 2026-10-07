<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Nvl\Support\Bindings\RequiredBindingDefinition;
use Nvl\Support\Bindings\RequiredBindings;
use Nvl\Support\Console\InstallCommand;
use Nvl\Support\Installation\ConfigPublisher;
use Nvl\Support\Installation\InstallationRegistry;
use Nvl\Support\Installation\PackageInstallation;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    $this->installationRoot = sys_get_temp_dir().'/nvl-install-'.bin2hex(random_bytes(8));
    mkdir($this->installationRoot);
    $this->installationApp = new Application($this->installationRoot);
    $this->installationConfig = new Repository;
    $this->required = new RequiredBindings($this->installationApp, $this->installationConfig);
    $this->registry = new InstallationRegistry($this->installationApp);
    $this->publisher = new ConfigPublisher($this->installationApp, $this->registry, $this->required);
    $this->source = $this->installationRoot.'/template.php';
    file_put_contents($this->source, '<?php return ["enabled" => false];');
    PackageInstallation::register($this->installationApp, 'nvl/example', ['nvl-example' => ['source' => $this->source, 'target' => 'nvl-example.php', 'tag' => 'nvl-example-config']]);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->installationRoot);
});

it('selects only loaded contributors and deduplicates logical and canonical names', function (): void {
    expect($this->registry->select(['example', 'nvl/example']))->toHaveCount(1);
    expect(fn () => $this->registry->select(['content']))->toThrow(InvalidArgumentException::class, 'not loaded');
    expect($this->publisher->publish([], dryRun: true)->configuration[0]['result'])->toBe('would_publish')
        ->and(is_dir($this->installationRoot.'/config'))->toBeFalse();
});

it('preserves host files and creates unique exact backups only on force', function (): void {
    $target = $this->installationRoot.'/config/nvl-example.php';
    $this->publisher->publish(['example']);
    file_put_contents($target, 'host private configuration');
    chmod($target, 0600);
    expect($this->publisher->publish(['example'])->configuration[0]['result'])->toBe('preserved');
    $dry = $this->publisher->publish(['example'], dryRun: true, force: true);
    expect($dry->configuration[0]['result'])->toBe('would_replace')->and(file_get_contents($target))->toBe('host private configuration');
    $first = $this->publisher->publish(['example'], force: true)->configuration[0];
    $second = $this->publisher->publish(['example'], force: true)->configuration[0];
    expect(file_get_contents($first['backup']))->toBe('host private configuration')
        ->and($second['backup'])->not->toBe($first['backup'])
        ->and(file_get_contents($target))->toBe(file_get_contents($this->source))
        ->and(fileperms($target) & 0777)->toBe(0600);
});

it('rejects linked destinations without modifying the linked file', function (): void {
    mkdir($this->installationRoot.'/config');
    symlink($this->source, $this->installationRoot.'/config/nvl-example.php');
    expect(fn () => $this->publisher->publish(['example'], force: true))->toThrow(RuntimeException::class)
        ->and(file_get_contents($this->source))->toBe('<?php return ["enabled" => false];');
});

it('reports enabled missing bindings without executing host factories', function (): void {
    $this->required->register(new RequiredBindingDefinition('example', 'HostAdapter', stdClass::class, 'remote', 'feature.enabled', 'https://example.test/docs'));
    expect($this->publisher->publish([], dryRun: true)->missingEnabledBindings)->toBeFalse();
    $this->installationConfig->set('feature.enabled', true);
    expect($this->publisher->publish([], dryRun: true)->missingEnabledBindings)->toBeTrue();
    $this->installationApp->bind('HostAdapter', fn () => throw new RuntimeException('adapter must never run'));
    expect($this->publisher->publish([], dryRun: true)->missingEnabledBindings)->toBeFalse()
        ->and($this->required->inspect()[0]->status)->toBe('configured');
});

it('returns a command failure only for selected enabled missing capabilities', function (): void {
    $command = new InstallCommand;
    $command->setLaravel($this->app);
    $this->app->instance(ConfigPublisher::class, $this->publisher);
    $this->required->register(new RequiredBindingDefinition('example', 'HostAdapter', stdClass::class, 'remote', 'feature.enabled', 'https://example.test/docs'));
    $tester = new CommandTester($command);
    expect($tester->execute(['packages' => ['example'], '--dry-run' => true, '--format' => 'json']))->toBe(0);
    $this->installationConfig->set('feature.enabled', true);
    expect($tester->execute(['packages' => ['example'], '--dry-run' => true, '--format' => 'json']))->toBe(1)
        ->and(json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['healthy'])->toBeFalse();
});

it('recommends the consumer audit for statically enforced owner relations', function (): void {
    $command = new InstallCommand;
    $command->setLaravel($this->app);
    $this->app->instance(ConfigPublisher::class, $this->publisher);
    $tester = new CommandTester($command);
    expect($tester->execute(['packages' => ['example'], '--dry-run' => true]))->toBe(0)
        ->and($tester->getDisplay())->toContain('vendor/nvl/core/support/consumer-audit.neon', 'enforced statically, not at runtime');
});
