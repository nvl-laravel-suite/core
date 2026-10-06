<?php

declare(strict_types=1);

namespace Nvl\Support\Doctor;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Throwable;

/**
 * Aggregates tagged checks from providers loaded by the consumer application.
 */
final readonly class DoctorRegistry
{
    /**
     * Retain the application container for lazy contributor discovery.
     */
    public function __construct(private Container $container) {}

    /**
     * Build a deterministic, versioned report and preserve contributor failures.
     *
     * @param  list<string>|null  $packages
     * @return array{schema_version: int, healthy: bool, strict: bool, checks: list<array{package: string, key: string, severity: string, result: string, message: string}>}
     */
    public function inspect(bool $strict = false, ?array $packages = null): array
    {
        $checks = [];
        $healthy = true;

        try {
            foreach ($this->container->tagged(DoctorContributor::class) as $contributor) {
                $package = 'nvl/core';

                try {
                    if (! $contributor instanceof DoctorContributor) {
                        throw new InvalidArgumentException('Tagged Doctors must implement DoctorContributor.');
                    }

                    $package = $contributor->package();
                    if ($packages !== null && ! in_array($package, $packages, true)) {
                        continue;
                    }

                    foreach ($contributor->inspect() as $check) {
                        $checks[] = $check->toArray($package);
                        $healthy = $healthy && ! $check->fails($strict);
                    }
                } catch (Throwable $exception) {
                    $checks[] = $this->executionFailure($package, $exception);
                    $healthy = false;
                }
            }
        } catch (Throwable $exception) {
            $checks[] = $this->executionFailure('nvl/core', $exception);
            $healthy = false;
        }

        usort($checks, static fn (array $left, array $right): int => [
            $left['package'], $left['key'], $left['severity'], $left['result'], $left['message'],
        ] <=> [
            $right['package'], $right['key'], $right['severity'], $right['result'], $right['message'],
        ]);

        return ['schema_version' => 1, 'healthy' => $healthy, 'strict' => $strict, 'checks' => $checks];
    }

    /**
     * Record failed contributor execution as an actionable error.
     *
     * @return array{package: string, key: string, severity: string, result: string, message: string}
     */
    private function executionFailure(string $package, Throwable $exception): array
    {
        return (new DoctorCheck(
            'contributor.execution',
            'error',
            false,
            'Doctor contributor failed: '.mb_substr($exception->getMessage(), 0, 500).'. Resolve the reported configuration or binding and rerun nvl:doctor.',
        ))->toArray($package);
    }
}
