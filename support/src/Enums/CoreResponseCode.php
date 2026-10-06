<?php

declare(strict_types=1);

namespace Nvl\Support\Enums;

use Nvl\Support\Contracts\ResponseCode;

/** Stable response codes owned by Core, including its neutral components.
 * @api
 */
enum CoreResponseCode: string implements ResponseCode
{
    case OperationFailed = 'operation_failed';
    case BindingRequired = 'binding_required';
    case EventCommitUnavailable = 'event_commit_unavailable';
}
