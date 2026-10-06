<?php

declare(strict_types=1);

namespace Nvl\Support\Owners;

use InvalidArgumentException;
use JsonSerializable;
use OutOfBoundsException;
use stdClass;

/**
 * Preserves owner DTO identity through object-valued JSON serialization.
 *
 * @api
 *
 * @template T of object
 */
final readonly class OwnerResultMap implements JsonSerializable
{
    /** @var array<string, array<array-key, T>> */
    private array $values;

    /** @var list<array{type: string, id: string}> */
    private array $requestedOrder;

    /**
     * Require exactly one DTO object for every requested identity.
     *
     * @param  array<string, array<array-key, T>>  $values
     *
     * @throws InvalidArgumentException
     */
    public function __construct(OwnerBatch $batch, array $values)
    {
        $retained = [];
        $order = [];
        foreach ($batch->identities() as $identity) {
            $value = $values[$identity->type][$identity->id] ?? null;
            if (! is_object($value)) {
                throw new InvalidArgumentException('Every requested owner requires an explicit DTO result.');
            }
            $retained[$identity->type][$identity->id] = $value;
            $order[] = ['type' => $identity->type, 'id' => $identity->id];
            unset($values[$identity->type][$identity->id]);
            if ($values[$identity->type] === []) {
                unset($values[$identity->type]);
            }
        }
        if ($values !== []) {
            throw new InvalidArgumentException('An owner result map contains unrequested identities.');
        }
        $this->values = $retained;
        $this->requestedOrder = $order;
    }

    /**
     * Return the DTO for one exact requested identity.
     *
     * @return T
     *
     * @throws OutOfBoundsException
     */
    public function get(string $type, string $id): object
    {
        return $this->values[$type][$id] ?? throw new OutOfBoundsException('The owner identity was not requested.');
    }

    /**
     * Return stable first-request order without encoding it in object key order.
     *
     * @return list<array{type: string, id: string}>
     */
    public function order(): array
    {
        return $this->requestedOrder;
    }

    /** Serialize both identity levels as JSON objects even for numeric keys. */
    public function jsonSerialize(): stdClass
    {
        $result = new stdClass;
        foreach ($this->values as $type => $owners) {
            $result->{$type} = (object) $owners;
        }

        return $result;
    }
}
