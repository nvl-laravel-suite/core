<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\ValueObjects;

use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;

/**
 * Captures only immutable scalar principal and owner references for one execution.
 *
 * @api
 */
final readonly class OwnershipSnapshot
{
    /**
     * Retain explicit execution classification.
     *
     * @param  array{kind: 'actor'|'system', type: string, id: string}|null  $principal
     * @param  array<string, string>  $generations
     */
    public function __construct(
        public OwnershipContextMode $mode,
        public ?OwnerReference $owner = null,
        public ?array $principal = null,
        public ?string $operation = null,
        public array $generations = [],
    ) {
        if (($mode === OwnershipContextMode::Owner) !== ($owner !== null)
            || (in_array($mode, [OwnershipContextMode::Owner, OwnershipContextMode::Central], true) !== ($principal !== null))
            || ($mode === OwnershipContextMode::Central && $operation === null)) {
            throw new OwnershipConfigurationInvalid('Ownership context mode and admission facts are inconsistent.');
        }
    }
}
