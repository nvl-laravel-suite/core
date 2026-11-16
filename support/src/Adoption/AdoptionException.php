<?php

declare(strict_types=1);

namespace Nvl\Support\Adoption;

use Nvl\Support\Exceptions\SupportException;

/**
 * Refuses an adoption phase whose plan, run state or committed data does not allow it.
 *
 * @api
 */
final class AdoptionException extends SupportException {}
