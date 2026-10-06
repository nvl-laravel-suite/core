<?php

declare(strict_types=1);

namespace Nvl\Support\Bindings;

use InvalidArgumentException;

/** Immutable declaration of one consumer-owned capability adapter.
 * @api
 */
final readonly class RequiredBindingDefinition
{
    /**
     * @param  string  $contract  Unvalidated capability contract identity
     * @param  string  $placeholder  Unvalidated default adapter identity
     */
    public function __construct(
        public string $package,
        public string $contract,
        public string $placeholder,
        public string $capability,
        public ?string $enabledWhen,
        public string $documentation,
    ) {
        if ($package === '' || $contract === '' || $placeholder === '' || $capability === '' || $documentation === '' || $enabledWhen === '') {
            throw new InvalidArgumentException('Required binding definitions require non-empty identities and documentation.');
        }
    }
}
