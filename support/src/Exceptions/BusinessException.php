<?php

declare(strict_types=1);

namespace Nvl\Support\Exceptions;

use Nvl\Support\Contracts\ResponseCode;
use Throwable;

/**
 * Domain failure carrying optional transport-neutral response metadata.
 */
class BusinessException extends SupportException
{
    /**
     * Create a transport-neutral business failure with safe and diagnostic context.
     *
     * @param  string  $message  Safe human-readable failure message
     * @param  ResponseCode|null  $responseCode  Stable machine-readable response code
     * @param  int  $suggestedStatus  Suggested presentation status
     * @param  array<string, mixed>  $publicContext  Context safe to expose through a consumer adapter
     * @param  array<string, mixed>  $diagnosticContext  Internal context intended only for logs and reports
     * @param  Throwable|null  $previous  Previous failure in the exception chain
     *
     * @throws SupportException When the suggested status is outside the supported range
     */
    public function __construct(
        string $message = '',
        private readonly ?ResponseCode $responseCode = null,
        private readonly int $suggestedStatus = 422,
        private readonly array $publicContext = [],
        private readonly array $diagnosticContext = [],
        ?Throwable $previous = null,
    ) {
        if ($this->suggestedStatus < 100 || $this->suggestedStatus > 599) {
            throw new SupportException('The suggested response status must be between 100 and 599.');
        }

        parent::__construct($message, 0, $previous);
    }

    /**
     * Resolve the backed response-code value when one was provided.
     *
     * @return string|null Stable machine-readable response code
     */
    public function responseCode(): ?string
    {
        return $this->responseCode === null
            ? null
            : (string) $this->responseCode->value;
    }

    /**
     * Return the suggested HTTP status for adapters that expose the failure.
     *
     * @return int Suggested presentation status
     */
    public function suggestedStatus(): int
    {
        return $this->suggestedStatus;
    }

    /**
     * Return structured context safe for an owning presentation adapter.
     *
     * @return array<string, mixed> Public response context
     */
    public function publicContext(): array
    {
        return $this->publicContext;
    }

    /**
     * Return internal diagnostic context for logs and exception reports.
     *
     * @return array<string, mixed> Internal diagnostic context
     */
    public function context(): array
    {
        return $this->diagnosticContext;
    }
}
