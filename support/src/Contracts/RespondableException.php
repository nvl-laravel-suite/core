<?php

declare(strict_types=1);

namespace Nvl\Support\Contracts;

/** Transport-neutral, explicitly public package failure metadata.
 * @api
 */
interface RespondableException extends PackageException
{
    public function package(): string;

    public function responseCode(): ?string;

    public function suggestedStatus(): int;

    /** @return array<string, mixed> */
    public function publicContext(): array;

    public function translationKey(): ?string;

    /** @return array<string, scalar|null> */
    public function translationParameters(): array;

    /** @return array<string, string> */
    public function responseHeaders(): array;
}
