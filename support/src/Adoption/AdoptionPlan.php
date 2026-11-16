<?php

declare(strict_types=1);

namespace Nvl\Support\Adoption;

use InvalidArgumentException;

/**
 * Immutable, reviewable description of one adoption: identity, fingerprint, ordered steps, expected counts and collisions.
 *
 * The fingerprint is a canonical hash of the source rows, the mapping and definition versions, the normalised options
 * and the source and destination schemas. It must leave out anything that changes while staging, such as destination
 * rows or counts. Collisions must cover the whole source graph, including staged destination rows that differ from
 * their source. That is the only drift detection: a resumed apply refuses a plan with collisions, and a checkpoint
 * never justifies overwriting.
 *
 * @api
 *
 * @phpstan-type Identity array<string, scalar|null>
 * @phpstan-type Metadata array{protocol: int, package: string, adapter_version: string, source: Identity, destination: Identity, options_digest: string, steps: list<array{id: string, depends_on: list<string>, cursor_columns: list<string>}>, expected: array<string, int>, collisions: int}
 */
final readonly class AdoptionPlan
{
    /** Version of the shared run, checkpoint and cursor protocol. */
    public const int Protocol = 1;

    /**
     * @param  Identity  $source  Names that identify the source (connection, database, tables); never credentials
     * @param  Identity  $destination  Names that identify the destination; never credentials
     * @param  list<AdoptionStep>  $steps  Steps in execution order
     * @param  array<string, int>  $expected  Expected mapped row count per step
     * @param  list<string>  $collisions  Human-readable reasons that block apply
     */
    public function __construct(
        public string $package,
        public string $adapterVersion,
        public string $fingerprint,
        public array $source,
        public array $destination,
        public string $optionsDigest,
        public array $steps,
        public array $expected = [],
        public array $collisions = [],
        public int $protocol = self::Protocol,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $package) !== 1) {
            throw new InvalidArgumentException("Adoption package [{$package}] must be a lowercase package slug.");
        }
        if ($adapterVersion === '' || strlen($adapterVersion) > 64) {
            throw new InvalidArgumentException('Adoption plans require an adapter version of at most 64 characters.');
        }
        foreach (['fingerprint' => $fingerprint, 'options digest' => $optionsDigest] as $name => $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new InvalidArgumentException("The adoption plan {$name} must be a lowercase SHA-256 digest.");
            }
        }
        if ($steps === []) {
            throw new InvalidArgumentException('Adoption plans require at least one ordered step.');
        }
        $seen = [];
        foreach ($steps as $step) {
            if (isset($seen[$step->id])) {
                throw new InvalidArgumentException("Adoption step [{$step->id}] is declared twice.");
            }
            foreach ($step->dependsOn as $dependency) {
                if (! isset($seen[$dependency])) {
                    throw new InvalidArgumentException("Adoption step [{$step->id}] depends on [{$dependency}], which must come earlier.");
                }
            }
            $seen[$step->id] = true;
        }
        foreach ($expected as $step => $count) {
            if (! isset($seen[$step]) || $count < 0) {
                throw new InvalidArgumentException("Adoption expected count for [{$step}] is invalid.");
            }
        }
    }

    /**
     * Hash a value canonically: maps are key-sorted at every depth and lists keep their order.
     */
    public static function digest(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Return the named step.
     */
    public function step(string $id): AdoptionStep
    {
        foreach ($this->steps as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }

        throw new AdoptionException("Adoption plan for [{$this->package}] has no step [{$id}].");
    }

    /**
     * Return the metadata stored with a run: identities, digests, steps and counts only.
     *
     * @return Metadata
     */
    public function metadata(): array
    {
        return [
            'protocol' => $this->protocol,
            'package' => $this->package,
            'adapter_version' => $this->adapterVersion,
            'source' => $this->source,
            'destination' => $this->destination,
            'options_digest' => $this->optionsDigest,
            'steps' => array_map(static fn (AdoptionStep $step): array => $step->toArray(), $this->steps),
            'expected' => $this->expected,
            'collisions' => count($this->collisions),
        ];
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);

            return (object) $value;
        }

        return $value;
    }
}
