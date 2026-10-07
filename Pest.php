<?php

declare(strict_types=1);

use Nvl\Data\Tests\TestCase as DataTestCase;
use Nvl\Support\Tests\SupportedSchemaDatabaseTestCase;
use Nvl\Support\Tests\TestCase as SupportTestCase;

uses(DataTestCase::class)->in(__DIR__.'/data/tests');
uses(getenv('NVL_SCHEMA_DATABASE') === '1' ? SupportedSchemaDatabaseTestCase::class : SupportTestCase::class)->in(
    __DIR__.'/support/tests/Feature',
    __DIR__.'/support/tests/PHPStan',
    ...array_values(array_filter(glob(__DIR__.'/support/tests/Unit/*Test.php') ?: [],
        static fn (string $path): bool => basename($path) !== 'FakeCallRecorderTest.php')),
);
