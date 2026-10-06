<?php

declare(strict_types=1);

namespace Nvl\Support\Doctor;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Adapts existing package-owned checks without executing or parsing a console command.
 */
final readonly class PackageDoctorContributor implements DoctorContributor
{
    /** @var Closure(): iterable<object|array<string, mixed>> */
    private Closure $inspector;

    /**
     * Create a lazy adapter around a package inspection boundary.
     *
     * @param  callable(): iterable<object|array<string, mixed>>  $inspector
     */
    public function __construct(private string $packageName, callable $inspector)
    {
        $this->inspector = Closure::fromCallable($inspector);
    }

    /**
     * Register a contributor only when its owning provider is loaded.
     *
     * @param  callable(): iterable<object|array<string, mixed>>  $inspector
     */
    public static function register(Container $container, string $package, callable $inspector): void
    {
        $binding = 'nvl.doctor.'.$package;
        $container->singleton($binding, static fn (): self => new self($package, $inspector));
        $container->tag($binding, DoctorContributor::class);
    }

    /**
     * Adapt legacy boolean readiness checks with package-specific remediation.
     *
     * @param  array<string, bool>  $checks
     * @return list<DoctorCheck>
     */
    public static function booleanChecks(array $checks, string $command): array
    {
        $results = [];
        foreach ($checks as $key => $passed) {
            $results[] = new DoctorCheck(
                $key,
                'error',
                $passed,
                $passed ? "Check [{$key}] is ready." : "Check [{$key}] failed; review package configuration and run {$command} --strict --format=json.",
            );
        }

        return $results;
    }

    /**
     * Preserve a legacy diagnostic report's authoritative readiness decision.
     *
     * @param  array<string, mixed>  $report
     * @return list<DoctorCheck>
     */
    public static function reportChecks(array $report, string $command): array
    {
        $results = [new DoctorCheck(
            'readiness',
            'error',
            ($report['healthy'] ?? false) === true,
            ($report['healthy'] ?? false) === true
                ? 'All required package readiness checks passed.'
                : "Required package readiness checks failed; run {$command} --strict --format=json and resolve the reported checks.",
        )];
        foreach ($report as $key => $value) {
            if ($key === 'healthy') {
                continue;
            }

            if (is_array($value)
                && is_string($value['severity'] ?? null)
                && is_bool($value['passed'] ?? null)
                && is_string($value['message'] ?? null)) {
                $results[] = new DoctorCheck($key, $value['severity'], $value['passed'], $value['message']);
            } else {
                $results[] = new DoctorCheck($key, 'info', true, 'Reported value: '.json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            }
        }

        return $results;
    }

    /**
     * Return the owning Composer package identity.
     */
    public function package(): string
    {
        return $this->packageName;
    }

    /**
     * Normalize each package-owned check to the Core diagnostic contract.
     *
     * @return iterable<DoctorCheck>
     */
    public function inspect(): iterable
    {
        foreach (($this->inspector)() as $check) {
            if ($check instanceof DoctorCheck) {
                yield $check;

                continue;
            }

            $values = is_array($check) ? $check : get_object_vars($check);
            $severity = $values['severity'] ?? 'error';
            $severity = $severity instanceof BackedEnum ? $severity->value : $severity;

            if (! is_string($values['key'] ?? null)
                || ! is_string($severity)
                || ! is_bool($values['passed'] ?? null)
                || ! is_string($values['message'] ?? null)) {
                throw new InvalidArgumentException('A package Doctor returned an invalid check; implement the DoctorContributor contract.');
            }

            yield new DoctorCheck($values['key'], $severity, $values['passed'], $values['message']);
        }
    }
}
