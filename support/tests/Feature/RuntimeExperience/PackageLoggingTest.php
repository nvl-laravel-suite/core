<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use Nvl\Support\Logging\PackageLogger;
use Psr\Log\LoggerInterface;

it('applies current package verbosity and emits severity diagnostics without leaking context', function (): void {
    $config = new Repository(['logging' => ['default' => 'host', 'channels' => ['host' => ['driver' => 'single']]], 'nvl-core' => ['logging' => ['verbosity' => 'normal', 'packages' => ['csv' => ['verbosity' => 'quiet']]]]]);
    $records = [];
    $sink = Mockery::mock(LoggerInterface::class);
    $sink->shouldReceive('log')->andReturnUsing(function ($level, $key, $context) use (&$records): void {
        $records[] = [$level, $key, $context];
    });
    $manager = Mockery::mock(LogManager::class);
    $manager->shouldReceive('channel')->with('nvl')->andReturn($sink);
    $logger = new PackageLogger($config, $manager);
    $logger->log('csv', 'info', 'nvl.csv.chunk.completed', ['rows' => 12]);
    $logger->log('csv', 'warning', 'nvl.csv.rows.failed', ['rows' => 2, 'email' => 'private@example.test', 'path' => '/private/input.csv', 'payload' => ['private'], 'exception' => new RuntimeException('secret'), 'nested' => ['private'], 'duration' => INF], 'verbose');
    $config->set('nvl-core.logging.packages.csv.verbosity', 'verbose');
    $logger->log('csv', 'debug', 'nvl.csv.chunk.started', ['rows' => 3], 'verbose');
    expect($records)->toHaveCount(2)
        ->and($records[0])->toBe(['warning', 'nvl.csv.rows.failed', ['rows' => 2, 'exception' => RuntimeException::class, 'package' => 'csv', 'message_key' => 'nvl.csv.rows.failed']])
        ->and($records[1][2])->toBe(['rows' => 3, 'package' => 'csv', 'message_key' => 'nvl.csv.chunk.started'])
        ->and($config->get('logging.default'))->toBe('host')
        ->and($config->get('logging.channels.nvl.channels'))->toBe(['host']);
});

it('preserves host channels and bounds each independent diagnostic', function (): void {
    $host = ['driver' => 'custom', 'via' => 'HostLogger'];
    $config = new Repository(['logging' => ['channels' => ['nvl' => $host]]]);
    $sink = Mockery::mock(LoggerInterface::class);
    $sink->shouldReceive('log')->once()->withArgs(fn ($level, $key, $context) => count($context) === 34 && strlen($context['item0']) === 191 && ! isset($context['item32']));
    $manager = Mockery::mock(LogManager::class);
    $manager->shouldReceive('channel')->once()->with('nvl')->andReturn($sink);
    $logger = new PackageLogger($config, $manager);
    $logger->log('media', 'error', 'nvl.media.storage.failed', array_fill_keys(array_map(fn ($i) => 'item'.$i, range(0, 40)), str_repeat('x', 300)));
    expect($config->get('logging.channels.nvl'))->toBe($host);
});

it('diagnoses cycles without resolving channels or mutating host configuration', function (): void {
    $config = new Repository(['logging' => ['default' => 'host', 'channels' => ['host' => ['driver' => 'stack', 'channels' => ['nvl']]]]]);
    $manager = Mockery::mock(LogManager::class);
    $manager->shouldNotReceive('channel');
    $logger = new PackageLogger($config, $manager);
    expect($logger->diagnostics())->toBe(['Package logging channel stack contains a cycle.'])
        ->and($config->has('logging.channels.nvl'))->toBeFalse();
    expect(fn () => $logger->log('media', 'error', 'nvl.media.storage.failed'))->toThrow(InvalidArgumentException::class, 'cycle');
});

it('rejects unstable message keys and invalid override policies', function (): void {
    $config = new Repository(['logging' => ['default' => 'host', 'channels' => ['host' => ['driver' => 'single']]], 'nvl-core' => ['logging' => ['packages' => ['media' => ['verbosity' => 'loud']]]]]);
    $manager = Mockery::mock(LogManager::class);
    $manager->shouldNotReceive('channel');
    $logger = new PackageLogger($config, $manager);
    expect(fn () => $logger->log('media', 'error', 'Failed for private customer'))->toThrow(InvalidArgumentException::class)
        ->and($logger->diagnostics())->toBe(['Package logging verbosity must be quiet, normal, or verbose.']);
});

it('diagnoses an invalid default policy even when Core has a valid override', function (): void {
    $config = new Repository(['logging' => ['channels' => ['nvl' => ['driver' => 'single']]], 'nvl-core' => ['logging' => ['verbosity' => 'invalid', 'packages' => ['core' => ['verbosity' => 'quiet']]]]]);
    $manager = Mockery::mock(LogManager::class);
    $manager->shouldNotReceive('channel');
    expect((new PackageLogger($config, $manager))->diagnostics())->toContain('Package logging verbosity must be quiet, normal, or verbose.');
});

it('uses a late native Log facade substitute after early singleton resolution', function (): void {
    $logger = app(PackageLogger::class);
    $sink = Mockery::mock(LoggerInterface::class);
    $sink->shouldReceive('log')->once()->with('warning', 'nvl.media.variation.missing', ['package' => 'media', 'message_key' => 'nvl.media.variation.missing']);
    Log::shouldReceive('channel')->once()->with('nvl')->andReturn($sink);
    $logger->log('media', 'warning', 'nvl.media.variation.missing');
});
