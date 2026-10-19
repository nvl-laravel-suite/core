<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\ValueObjects;

use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/**
 * Carries host-verified public identity, independently of locale and content scope.
 * This value is data; only the public admission runner establishes authority.
 *
 * @api
 */
final readonly class VerifiedOwnerSiteContext
{
    /** @var list<string> */
    public array $resources;

    /** @var list<string> */
    public array $writableResources;

    /**
     * Retain explicit public read and write capabilities for one canonical origin.
     *
     * @param  array<array-key,mixed>  $resources
     * @param  array<array-key,mixed>  $writableResources
     */
    public function __construct(
        public OwnerReference $owner,
        public string $site,
        public string $canonicalOrigin,
        array $resources,
        array $writableResources = [],
    ) {
        $this->resources = self::capabilities($resources, false);
        $this->writableResources = self::capabilities($writableResources, true);
        $parts = parse_url($canonicalOrigin);
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $site) !== 1
            || ! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! isset($parts['host']) || isset($parts['user'], $parts['pass'])
            || array_diff(array_keys($parts), ['scheme', 'host', 'port']) !== []
            || array_diff($this->writableResources, $this->resources) !== []) {
            throw new OwnershipViolation('Public sites require a canonical origin and explicit resource capabilities.');
        }
    }

    /** Validate raw host adapter input before storing a typed immutable capability list.
     * @param  array<array-key,mixed>  $values
     * @return list<string>
     */
    private static function capabilities(array $values, bool $allowEmpty): array
    {
        if (! array_is_list($values) || (! $allowEmpty && $values === [])) {
            throw new OwnershipViolation('Public capabilities require explicit lists.');
        }
        $validated = [];
        foreach ($values as $resource) {
            if (! is_string($resource) || preg_match('/^[a-z0-9][a-z0-9._-]{0,190}$/D', $resource) !== 1) {
                throw new OwnershipViolation('Invalid public site resource capability.');
            }
            $validated[] = $resource;
        }

        return $validated;
    }
}
