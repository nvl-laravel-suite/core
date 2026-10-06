<?php

declare(strict_types=1);

namespace Nvl\Support\Exceptions;

use InvalidArgumentException;
use Nvl\Support\Contracts\ResponseCode;

/** Immutable public presentation metadata, independent of an HTTP transport.
 * @api
 */
final readonly class ExceptionResponse
{
    /**
     * @param  array<string, mixed>  $publicContext
     * @param  array<string, scalar|null>  $translationParameters
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $package,
        public ResponseCode $code,
        public int $status = 422,
        public array $publicContext = [],
        public array $translationParameters = [],
        public array $headers = [],
    ) {
        if ($package === '' || $status < 100 || $status > 599) {
            throw new InvalidArgumentException('Exception responses require a package and a status between 100 and 599.');
        }
    }
}
