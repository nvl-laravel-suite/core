<?php

declare(strict_types=1);

namespace Nvl\Support\Adoption;

use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Names one ordered adoption step and the keyset columns that make its batches resumable.
 *
 * @api
 *
 * @phpstan-type Cursor array<string, int|string>
 */
final readonly class AdoptionStep
{
    /**
     * @param  list<string>  $dependsOn  Step identifiers that must complete first; they must appear earlier in the plan
     * @param  list<string>  $cursorColumns  Unique, non-null source columns ordered as the keyset
     */
    public function __construct(
        public string $id,
        public array $dependsOn = [],
        public array $cursorColumns = ['id'],
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $id) !== 1) {
            throw new InvalidArgumentException("Adoption step [{$id}] must be a lowercase identifier of at most 100 characters.");
        }
        if (in_array($id, $dependsOn, true) || count(array_unique($dependsOn)) !== count($dependsOn)) {
            throw new InvalidArgumentException("Adoption step [{$id}] has invalid dependencies.");
        }
        if ($cursorColumns === [] || count(array_unique($cursorColumns)) !== count($cursorColumns)
            || array_filter($cursorColumns, static fn (string $column): bool => $column === '') !== []) {
            throw new InvalidArgumentException("Adoption step [{$id}] requires distinct cursor columns.");
        }
    }

    /**
     * Read the keyset position of one source row.
     *
     * @param  array<array-key, mixed>|object  $row
     * @return Cursor
     */
    public function cursorFrom(array|object $row): array
    {
        $values = is_array($row) ? $row : get_object_vars($row);
        $cursor = [];
        foreach ($this->cursorColumns as $column) {
            $value = $values[$column] ?? null;
            if (! is_int($value) && ! is_string($value)) {
                throw new InvalidArgumentException("Adoption step [{$this->id}] cursor column [{$column}] must hold a non-null integer or string.");
            }
            $cursor[$column] = $value;
        }

        return $cursor;
    }

    /**
     * Constrain a source query to the rows after a keyset cursor, in cursor order. Never use offsets.
     *
     * @param  Cursor|null  $cursor
     */
    public function page(Builder $query, ?array $cursor, int $limit): Builder
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Adoption batches require a positive limit.');
        }
        if ($cursor !== null) {
            $this->assertCursor($cursor);
            $query->where(function (Builder $after) use ($cursor): void {
                foreach ($this->cursorColumns as $position => $column) {
                    $after->orWhere(function (Builder $branch) use ($cursor, $position, $column): void {
                        foreach (array_slice($this->cursorColumns, 0, $position) as $previous) {
                            $branch->where($previous, '=', $cursor[$previous]);
                        }
                        $branch->where($column, '>', $cursor[$column]);
                    });
                }
            });
        }
        foreach ($this->cursorColumns as $column) {
            $query->orderBy($column);
        }

        return $query->limit($limit);
    }

    /**
     * Reject a cursor that does not name exactly this step's keyset columns.
     *
     * @param  array<array-key, mixed>  $cursor
     */
    public function assertCursor(array $cursor): void
    {
        if (array_keys($cursor) !== $this->cursorColumns) {
            throw new AdoptionException("Adoption step [{$this->id}] received a cursor that does not match its keyset columns.");
        }
        foreach ($cursor as $value) {
            if (! is_int($value) && ! is_string($value)) {
                throw new AdoptionException("Adoption step [{$this->id}] cursor values must be integers or strings.");
            }
        }
    }

    /**
     * @return array{id: string, depends_on: list<string>, cursor_columns: list<string>}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'depends_on' => $this->dependsOn, 'cursor_columns' => $this->cursorColumns];
    }
}
