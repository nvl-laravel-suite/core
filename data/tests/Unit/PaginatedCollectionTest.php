<?php

declare(strict_types=1);

namespace Nvl\Data\Tests\Unit;

use Illuminate\Pagination\LengthAwarePaginator;
use Nvl\Data\Data\PaginatedCollection;
use Nvl\Data\Tests\Fixtures\PaginationItemData;

it('normalizes paginator items and metadata through data objects', function (): void {
    $paginator = new LengthAwarePaginator(
        items: [
            ['id' => 1, 'name' => 'First'],
            ['id' => 2, 'name' => 'Second'],
        ],
        total: 5,
        perPage: 2,
        currentPage: 2,
    );

    $payload = PaginatedCollection::fromPaginator($paginator, PaginationItemData::class)->toArray();

    expect($payload)->toBe([
        'items' => [
            ['id' => 1, 'name' => 'First'],
            ['id' => 2, 'name' => 'Second'],
        ],
        'meta' => [
            'currentPage' => 2,
            'lastPage' => 3,
            'perPage' => 2,
            'total' => 5,
        ],
    ]);
});
