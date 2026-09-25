<?php

declare(strict_types=1);

namespace Nvl\Data\Data;

use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * Normalizes a Laravel paginator into the stable NVL pagination envelope.
 */
final class PaginatedCollection extends Data
{
    /**
     * Create a normalized pagination envelope.
     *
     * @param  list<array<string, mixed>>  $items  Transformed page items
     */
    public function __construct(
        public readonly array $items,
        public readonly PaginationMeta $meta,
    ) {}

    /**
     * Transform one paginator with a Spatie Data item class.
     *
     * @param  object  $paginator  Source length-aware paginator
     * @param  class-string<Data>  $dataClass  Item data class
     */
    public static function fromPaginator(object $paginator, string $dataClass): self
    {
        if (! $paginator instanceof LengthAwarePaginator) {
            throw new InvalidArgumentException('Paginated data requires a Laravel length-aware paginator.');
        }

        return new self(
            items: array_values(array_map(
                static fn (mixed $item): array => self::stringKeyed(
                    $dataClass::from($item)->toArray(),
                ),
                $paginator->items(),
            )),
            meta: new PaginationMeta(
                currentPage: $paginator->currentPage(),
                lastPage: $paginator->lastPage(),
                perPage: $paginator->perPage(),
                total: $paginator->total(),
            ),
        );
    }

    /**
     * Normalize a Data payload to its documented string-keyed shape.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
