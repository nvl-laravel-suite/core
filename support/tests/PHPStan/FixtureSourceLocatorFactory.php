<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\PHPStan;

use PHPStan\BetterReflection\SourceLocator\Type\AggregateSourceLocator;
use PHPStan\BetterReflection\SourceLocator\Type\SourceLocator;
use PHPStan\Reflection\BetterReflection\SourceLocator\OptimizedSingleFileSourceLocatorRepository;
use PHPStan\Testing\TestCaseSourceLocatorFactory;

/** Supplies fixture declarations to PHPStan without executing fixture PHP. */
final readonly class FixtureSourceLocatorFactory
{
    /** Retain PHPStan's source readers. */
    public function __construct(private TestCaseSourceLocatorFactory $factory, private OptimizedSingleFileSourceLocatorRepository $files) {}

    /** Add source-only host fixtures ahead of the ordinary test source locator. */
    public function create(): SourceLocator
    {
        $locators = [];
        foreach (glob(__DIR__.'/Fixtures/*.php.stub') ?: [] as $file) {
            $locators[] = $this->files->getOrCreate($file);
        }
        $locators[] = $this->factory->create();

        return new AggregateSourceLocator($locators);
    }
}
