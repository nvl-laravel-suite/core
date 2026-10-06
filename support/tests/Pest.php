<?php

declare(strict_types=1);

use Nvl\Support\Tests\SupportedSchemaDatabaseTestCase;
use Nvl\Support\Tests\TestCase;

uses(getenv('NVL_SCHEMA_DATABASE') === '1' ? SupportedSchemaDatabaseTestCase::class : TestCase::class)->in(
    __DIR__.'/Feature',
    __DIR__.'/PHPStan',
    ...array_values(array_filter(
        glob(__DIR__.'/Unit/*Test.php') ?: [],
        static fn (string $path): bool => basename($path) !== 'FakeCallRecorderTest.php',
    )),
);
