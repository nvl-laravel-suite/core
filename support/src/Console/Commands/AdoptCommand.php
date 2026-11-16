<?php

declare(strict_types=1);

namespace Nvl\Support\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use JsonException;
use Nvl\Support\Adoption\AdoptionCoordinator;
use Nvl\Support\Adoption\AdoptionPlan;
use Nvl\Support\Adoption\AdoptionProgress;
use Nvl\Support\Adoption\AdoptionRegistry;
use Nvl\Support\Enums\SystemActor;
use Throwable;

/**
 * Plans, applies, verifies and activates a registered package adoption as the explicit background actor.
 */
final class AdoptCommand extends Command
{
    private const int MaximumManifestBytes = 1_048_576;

    /** @var string */
    protected $signature = 'nvl:adopt {package : Registered adoption package} {phase=plan : plan, apply, verify or activate} {manifest? : JSON options file} {--fingerprint= : Reviewed plan fingerprint} {--run= : Existing run to resume} {--batch-size= : Rows per committed batch} {--format=text : text or json}';

    /** @var string */
    protected $description = 'Run a reviewed, checkpointed package adoption phase';

    /** Run one phase and report run, fingerprint, state, cursors, counts and collisions. */
    public function handle(AdoptionRegistry $registry, Repository $config): int
    {
        $coordinator = null;
        $phase = null;
        try {
            if (! in_array($this->option('format'), ['text', 'json'], true)) {
                throw new InvalidArgumentException('The format must be text or json.');
            }
            $package = $this->stringArgument('package');
            $phase = $this->stringArgument('phase');
            if (! in_array($phase, ['plan', 'apply', 'verify', 'activate'], true)) {
                throw new InvalidArgumentException('The phase must be plan, apply, verify or activate.');
            }
            $fingerprint = $this->stringOption('fingerprint');
            $run = $this->stringOption('run');
            if (in_array($phase, ['apply', 'activate'], true) && $fingerprint === null) {
                throw new InvalidArgumentException("The {$phase} phase requires --fingerprint from a reviewed plan.");
            }
            if ($run !== null && $phase !== 'apply') {
                throw new InvalidArgumentException('The --run option only resumes the apply phase.');
            }
            $coordinator = new AdoptionCoordinator($registry->make($package, SystemActor::Background, $this->manifest()));

            $verification = $phase === 'verify' ? $coordinator->verify() : null;
            $report = match ($phase) {
                'plan' => $this->planned($coordinator),
                'apply' => $this->progressed($coordinator->apply((string) $fingerprint, $this->batchSize($config), $run)),
                'verify' => ['verification' => $verification?->toArray()],
                'activate' => $this->progressed($coordinator->activate((string) $fingerprint)),
            };
            $report = ['schema_version' => 1, 'package' => $package, 'phase' => $phase, ...$report];
            if ($this->option('format') === 'json') {
                $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->render($report);
            }

            return $verification?->passed === false ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $progress = $phase === 'apply' && $coordinator !== null ? $this->failedProgress($coordinator) : null;
            if ($this->option('format') === 'json') {
                $this->line(json_encode([
                    'schema_version' => 1, 'error' => $exception->getMessage(),
                    ...($progress === null ? [] : ['run' => $progress->run, 'state' => $progress->state]),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error($exception->getMessage());
                if ($progress !== null) {
                    $this->line("Run: {$progress->run} ({$progress->state})");
                }
            }

            return self::FAILURE;
        }
    }

    /**
     * Best-effort lookup of the run a failed apply left behind, so operators can resume it.
     */
    private function failedProgress(AdoptionCoordinator $coordinator): ?AdoptionProgress
    {
        try {
            return $coordinator->progress($coordinator->plan());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function planned(AdoptionCoordinator $coordinator): array
    {
        $plan = $coordinator->plan();
        $progress = $coordinator->progress($plan);

        return [
            'run' => $progress?->run,
            'fingerprint' => $plan->fingerprint,
            'state' => $progress === null ? AdoptionProgress::Planned : $progress->state,
            ...$this->steps($plan, $progress),
            'collisions' => $plan->collisions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function progressed(AdoptionProgress $progress): array
    {
        $cursors = $counts = [];
        foreach ($progress->steps as $step => $state) {
            $cursors[$step] = $state['cursor'];
            $counts[$step] = ['expected' => $state['expected'], 'processed' => $state['processed'], 'complete' => $state['complete']];
        }

        return [
            'run' => $progress->run, 'fingerprint' => $progress->fingerprint, 'state' => $progress->state,
            'cursors' => $cursors, 'counts' => $counts, 'collisions' => [],
            'batches' => $progress->written, 'verified_at' => $progress->verifiedAt, 'activated_at' => $progress->activatedAt,
        ];
    }

    /**
     * @return array{cursors: array<string, mixed>, counts: array<string, array{expected: int|null, processed: int, complete: bool}>}
     */
    private function steps(AdoptionPlan $plan, ?AdoptionProgress $progress): array
    {
        $cursors = $counts = [];
        foreach ($plan->steps as $step) {
            $state = $progress?->steps[$step->id] ?? null;
            $cursors[$step->id] = $state['cursor'] ?? null;
            $counts[$step->id] = ['expected' => $plan->expected[$step->id] ?? null, 'processed' => $state['processed'] ?? 0, 'complete' => $state['complete'] ?? false];
        }

        return ['cursors' => $cursors, 'counts' => $counts];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report): void
    {
        foreach (['run', 'fingerprint', 'state'] as $field) {
            if (is_string($report[$field] ?? null)) {
                $this->line(ucfirst($field).': '.$report[$field]);
            }
        }
        if (is_array($report['counts'] ?? null) && is_array($report['cursors'] ?? null)) {
            $rows = [];
            foreach ($report['counts'] as $step => $count) {
                $cursor = $report['cursors'][$step] ?? null;
                $rows[] = [
                    (string) $step,
                    is_array($count) && is_int($count['expected'] ?? null) ? (string) $count['expected'] : '-',
                    is_array($count) && is_int($count['processed'] ?? null) ? (string) $count['processed'] : '0',
                    is_array($count) && ($count['complete'] ?? false) === true ? 'yes' : 'no',
                    $cursor === null ? '-' : json_encode($cursor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
            }
            $this->table(['Step', 'Expected', 'Processed', 'Complete', 'Cursor'], $rows);
        }
        foreach (is_array($report['collisions'] ?? null) ? $report['collisions'] : [] as $collision) {
            $this->warn(is_string($collision) ? $collision : '');
        }
        $verification = $report['verification'] ?? null;
        if (is_array($verification)) {
            $this->table(['Passed', 'Missing', 'Extra', 'Changed', 'Digest'], [[
                ($verification['passed'] ?? false) === true ? 'yes' : 'no',
                (string) (is_int($verification['missing'] ?? null) ? $verification['missing'] : 0),
                (string) (is_int($verification['extra'] ?? null) ? $verification['extra'] : 0),
                (string) (is_int($verification['changed'] ?? null) ? $verification['changed'] : 0),
                is_string($verification['digest'] ?? null) ? $verification['digest'] : '',
            ]]);
            foreach (is_array($verification['problems'] ?? null) ? $verification['problems'] : [] as $problem) {
                $this->warn(is_string($problem) ? $problem : '');
            }
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function manifest(): array
    {
        $manifest = $this->argument('manifest');
        if ($manifest === null) {
            return [];
        }
        if ($manifest === '') {
            throw new InvalidArgumentException('The manifest must be a JSON file path.');
        }
        $path = str_starts_with($manifest, DIRECTORY_SEPARATOR) ? $manifest : base_path($manifest);
        $size = is_file($path) ? filesize($path) : false;
        if (! is_int($size) || $size > self::MaximumManifestBytes) {
            throw new InvalidArgumentException("Adoption manifest [{$path}] is missing or exceeds ".self::MaximumManifestBytes.' bytes.');
        }
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new InvalidArgumentException("Adoption manifest [{$path}] cannot be read.");
        }
        try {
            $options = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("Adoption manifest [{$path}] is not valid JSON.", previous: $exception);
        }
        if (! is_array($options) || ($options !== [] && array_is_list($options))) {
            throw new InvalidArgumentException('The adoption manifest must be a JSON object.');
        }

        return $options;
    }

    private function batchSize(Repository $config): int
    {
        $option = $this->option('batch-size');
        $size = $option === null ? $config->get('nvl-core.adoption.batch_size', 100) : filter_var($option, FILTER_VALIDATE_INT);
        if (! is_int($size) || $size < 1 || $size > 10_000) {
            throw new InvalidArgumentException('The adoption batch size must be an integer between 1 and 10000.');
        }

        return $size;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : throw new InvalidArgumentException("The {$name} argument is required.");
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === null) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : throw new InvalidArgumentException("The --{$name} option requires a value.");
    }
}
