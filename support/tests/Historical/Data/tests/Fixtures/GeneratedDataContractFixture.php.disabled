<?php

declare(strict_types=1);

namespace Nvl\Data\Tests\Fixtures;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Exercises NVL-specific collection, optional, date, model, and mutability extraction.
 */
#[TypeScript]
final class GeneratedDataContractFixture extends Data
{
    /**
     * Create the representative generated Data contract.
     *
     * @param  Collection<int, GeneratedCollectionItemFixture>  $items
     */
    public function __construct(
        #[DataCollectionOf(GeneratedCollectionItemFixture::class)]
        public readonly Collection $items,
        public readonly string|Optional $note,
        public readonly DateTimeImmutable $publishedAt,
        public readonly Carbon $reviewedAt,
        public readonly Model $owner,
    ) {}
}
