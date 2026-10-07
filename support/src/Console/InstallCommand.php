<?php

declare(strict_types=1);

namespace Nvl\Support\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Nvl\Support\Installation\ConfigPublisher;

/** Publishes selected loaded-package configs and reports host binding requirements. */
final class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:install {packages?*} {--dry-run} {--force} {--format=text}';

    /** @var string */
    protected $description = 'Publish NVL package configs without enabling features or changing schema';

    /** Validate selection options and render the immutable publication report. */
    public function handle(ConfigPublisher $publisher): int
    {
        $packages = $this->input->getArgument('packages');
        $format = $this->option('format');
        if (! is_array($packages) || ! array_is_list($packages) || ! is_string($format) || ! in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException('Use package names and --format=text or --format=json.');
        }
        foreach ($packages as $package) {
            if (! is_string($package)) {
                throw new InvalidArgumentException('Installer package names must be strings.');
            }
        }
        $report = $publisher->publish($packages, (bool) $this->option('dry-run'), (bool) $this->option('force'));
        if ($format === 'json') {
            $this->line(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($report->configuration as $configuration) {
                $this->line($configuration['result'].': '.$configuration['path']);
                if ($configuration['backup'] !== null) {
                    $this->line('backup: '.$configuration['backup']);
                }
            }
            foreach ($report->bindings as $binding) {
                $this->line($binding->status.': '.$binding->message.' '.$binding->documentation);
            }
            $this->line('Enable vendor/nvl/core/support/consumer-audit.neon in PHPStan. Owner relation boundaries are enforced statically, not at runtime.');
            $this->line('Review config, bind selected capabilities, then run nvl:doctor --strict. Refresh config cache explicitly when deploying.');
        }

        return $report->missingEnabledBindings ? self::FAILURE : self::SUCCESS;
    }
}
