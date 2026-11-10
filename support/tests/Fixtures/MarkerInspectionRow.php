<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use stdClass;

/** Counts identity inspections on real PDO rows without timing assertions. */
final class MarkerInspectionRow extends stdClass
{
    public static int $identityReads = 0;

    public string $resource {
        get {
            self::$identityReads++;

            return $this->resource;
        }
    }

    public string $physical_table {
        get {
            self::$identityReads++;

            return $this->physical_table;
        }
    }
}
