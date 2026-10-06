<?php

declare(strict_types=1);

namespace Nvl\Support\Console;

use Illuminate\Console\Command;
use Nvl\Support\Doctor\DoctorRegistry;

/**
 * Runs the consumer-owned gate over loaded NVL package diagnostics.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'nvl:doctor {--strict : Treat warnings as failures} {--format=text : Output format: text or json}';

    protected $description = 'Inspect loaded NVL packages without changing application state';

    /**
     * Render a versioned report and return a failing status for diagnostic errors.
     */
    public function handle(DoctorRegistry $registry): int
    {
        $format = $this->option('format');
        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $report = $registry->inspect((bool) $this->option('strict'));
        if ($format === 'json') {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Package', 'Check', 'Severity', 'Result', 'Message'], array_map(
                static fn (array $check): array => array_values($check),
                $report['checks'],
            ));
        }

        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
