<?php

declare(strict_types=1);

namespace Nvl\Support\Exceptions;

use Nvl\Support\Enums\CoreResponseCode;

/** A selected package capability requires a consumer-owned adapter.
 * @api
 */
final class BindingRequiredException extends BusinessException
{
    private string $ownerPackage = 'core';

    /** Return the package whose selected capability needs the adapter. */
    public function package(): string
    {
        return $this->ownerPackage;
    }

    /** Create a configuration failure with diagnostics confined to PHP. */
    public static function for(string $package, string $contract, string $capability): self
    {
        $exception = new self(
            message: "Package [{$package}] capability [{$capability}] requires host binding [{$contract}]. See packages/nvl/{$package}/README.md#required-bindings.",
            responseCode: CoreResponseCode::BindingRequired,
            suggestedStatus: 500,
        );
        $exception->ownerPackage = $package;

        return $exception;
    }
}
