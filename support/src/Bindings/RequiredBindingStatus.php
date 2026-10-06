<?php

declare(strict_types=1);

namespace Nvl\Support\Bindings;

use InvalidArgumentException;

/** Registration status without resolving or executing the adapter.
 * @api
 */
final readonly class RequiredBindingStatus
{
    /** @param string $status Unvalidated registry status */
    public function __construct(
        public string $package,
        public string $contract,
        public string $capability,
        public string $status,
        public string $message,
        public string $documentation,
    ) {
        if (! in_array($status, ['missing', 'configured', 'inactive'], true)) {
            throw new InvalidArgumentException('Unknown required binding status.');
        }
    }
}
