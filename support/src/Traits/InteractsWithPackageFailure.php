<?php

declare(strict_types=1);

namespace Nvl\Support\Traits;

use Nvl\Support\Exceptions\ExceptionResponse;

/** Adapts existing exception hierarchies to public response metadata.
 * @api
 */
trait InteractsWithPackageFailure
{
    abstract protected function exceptionResponse(): ExceptionResponse;

    public function package(): string
    {
        return $this->exceptionResponse()->package;
    }

    public function responseCode(): ?string
    {
        return (string) $this->exceptionResponse()->code->value;
    }

    public function suggestedStatus(): int
    {
        return $this->exceptionResponse()->status;
    }

    /** @return array<string, mixed> */
    public function publicContext(): array
    {
        return $this->exceptionResponse()->publicContext;
    }

    public function translationKey(): ?string
    {
        $response = $this->exceptionResponse();

        return 'nvl-'.$response->package.'::responsecode.'.$response->code->value;
    }

    /** @return array<string, scalar|null> */
    public function translationParameters(): array
    {
        return $this->exceptionResponse()->translationParameters;
    }

    /** @return array<string, string> */
    public function responseHeaders(): array
    {
        return $this->exceptionResponse()->headers;
    }
}
