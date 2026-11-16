<?php

declare(strict_types=1);

namespace Nvl\Support\Adoption;

use InvalidArgumentException;

/**
 * Result of an independent scan of committed source and destination rows.
 *
 * @api
 */
final readonly class AdoptionVerification
{
    /** True when nothing is missing, extra or changed and no problem was reported. */
    public bool $passed;

    /**
     * @param  string  $digest  Canonical SHA-256 digest of the verified destination state
     * @param  list<string>  $problems  Human-readable findings, such as a missing backing file
     */
    public function __construct(
        public int $missing,
        public int $extra,
        public int $changed,
        public string $digest,
        public array $problems = [],
    ) {
        if ($missing < 0 || $extra < 0 || $changed < 0 || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new InvalidArgumentException('Adoption verification requires non-negative counts, a SHA-256 digest and a list of problems.');
        }
        $this->passed = $missing === 0 && $extra === 0 && $changed === 0 && $problems === [];
    }

    /**
     * Return a copy with additional coordinator findings.
     *
     * @param  list<string>  $problems
     */
    public function withProblems(array $problems): self
    {
        return $problems === [] ? $this : new self($this->missing, $this->extra, $this->changed, $this->digest, [...$this->problems, ...$problems]);
    }

    /**
     * @return array{passed: bool, missing: int, extra: int, changed: int, digest: string, problems: list<string>}
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed, 'missing' => $this->missing, 'extra' => $this->extra,
            'changed' => $this->changed, 'digest' => $this->digest, 'problems' => $this->problems,
        ];
    }
}
