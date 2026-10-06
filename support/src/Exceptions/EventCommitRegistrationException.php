<?php

declare(strict_types=1);

namespace Nvl\Support\Exceptions;

use Nvl\Support\Enums\CoreResponseCode;

/** Rejects publication when the active source transaction has no tracked native record.
 *
 * @api
 */
final class EventCommitRegistrationException extends BusinessException
{
    /** Describe unavailable commit infrastructure without exposing connection identities. */
    public function __construct()
    {
        parent::__construct('The active event source transaction is not registered with the host transaction manager.', responseCode: CoreResponseCode::EventCommitUnavailable, suggestedStatus: 500);
    }

    /** Return the stable configuration failure code. */
    public function responseCode(): string
    {
        return 'event_commit_unavailable';
    }
}
