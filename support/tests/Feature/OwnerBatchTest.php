<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\User;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Owners\OwnerIdentity;
use Nvl\Support\Owners\OwnerResultMap;

beforeEach(function (): void {
    $this->originalMorphMap = Relation::morphMap();
    $this->originalMorphRequirement = Relation::requiresMorphMap();
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
});

afterEach(function (): void {
    Relation::morphMap($this->originalMorphMap, false);
    Relation::requireMorphMap($this->originalMorphRequirement);
});

/** Create a persisted owner identity without touching storage. */
function batchIdentityOwner(string|int $id, ?string $connection = null): Model
{
    $owner = new class extends Model
    {
        protected $guarded = [];
    };
    $owner->setRawAttributes(['id' => $id]);
    $owner->setKeyType('string');
    $owner->setConnection($connection);
    $owner->exists = true;

    return $owner;
}

it('preserves native morph identity and scalar key spelling', function (string|int $key): void {
    $owner = batchIdentityOwner($key);
    $identity = OwnerIdentity::fromModel($owner);

    expect($identity->type)->toBe($owner::class)
        ->and($identity->id)->toBe((string) $key)
        ->and($identity->model)->toBe($owner::class)
        ->and($identity->connection)->toBeNull();

    Relation::morphMap(['host.article:v2' => $owner::class]);
    expect(OwnerIdentity::fromModel($owner)->type)->toBe('host.article:v2');
})->with([7, '007', 'customer:A:7', '00000000-0000-4000-8000-000000000007']);

it('normalizes an integer owner key through native model casting', function (): void {
    $owner = batchIdentityOwner('007');
    $owner->setKeyType('int');

    expect(OwnerIdentity::fromModel($owner)->id)->toBe((string) $owner->getKey());
});

it('deduplicates exact identities while preserving the first input order', function (): void {
    $first = batchIdentityOwner(7);
    $second = batchIdentityOwner('007');
    $batch = OwnerBatch::fromModels([$first, $second, clone $first]);

    expect($batch->owners())->toBe([$first, $second])
        ->and(array_map(static fn (OwnerIdentity $identity): string => $identity->id, $batch->identities()))->toBe(['7', '007'])
        ->and($batch->isEmpty())->toBeFalse();
});

it('accepts identical duplicate attributes regardless of column order', function (): void {
    $owner = batchIdentityOwner('007');
    $owner->setRawAttributes(['id' => '007', 'tenant_id' => 'tenant-one']);
    $duplicate = clone $owner;
    $duplicate->setRawAttributes(['tenant_id' => 'tenant-one', 'id' => '007']);

    expect(OwnerBatch::fromModels([$owner, $duplicate])->owners())->toBe([$owner]);
    $duplicate->setRawAttributes(['tenant_id' => 'tenant-one', 'id' => 7]);
    $duplicate->setKeyType('int');
    $owner->setKeyType('int');
    expect(fn () => OwnerBatch::fromModels([$owner, $duplicate]))->toThrow(InvalidArgumentException::class);
});

it('retains immutable identity snapshots alongside live owner references', function (): void {
    $owner = batchIdentityOwner('original');
    $batch = OwnerBatch::fromModels([$owner]);
    $owner->setRawAttributes(['id' => 'changed']);
    $owner->exists = false;

    expect($batch->owners()[0])->toBe($owner)
        ->and($batch->identities()[0]->id)->toBe('original')
        ->and($batch->owners()[0]->getKey())->toBe('changed');
});

it('rejects conflicting metadata for one native identity', function (string $field): void {
    $owner = batchIdentityOwner(7);
    $other = clone $owner;
    if ($field === 'connection') {
        $other->setConnection('foreign');
    } else {
        $other->setRawAttributes(['id' => 7, 'tenant_id' => 'forged']);
    }

    expect(fn () => OwnerBatch::fromModels([$owner, $other]))->toThrow(InvalidArgumentException::class);
})->with(['connection', 'tenant_id']);

it('rejects ambiguous native identities shared by concrete classes', function (): void {
    $first = batchIdentityOwner(7);
    $second = new class extends Model
    {
        public function getMorphClass(): string
        {
            return 'shared';
        }
    };
    $second->setRawAttributes(['id' => 7]);
    $second->exists = true;
    Relation::morphMap(['shared' => $first::class]);

    expect(fn () => OwnerBatch::fromModels([$first, $second]))->toThrow(InvalidArgumentException::class);
});

it('rejects invalid owners before any storage operation', function (string $problem): void {
    $owner = batchIdentityOwner(7);
    if ($problem === 'unpersisted') {
        $owner->exists = false;
    } elseif ($problem === 'missing') {
        $owner->setRawAttributes([]);
    } elseif ($problem === 'empty') {
        $owner->setRawAttributes(['id' => '']);
    } else {
        $owner->setRawAttributes(['id' => ['invalid']]);
    }

    expect(fn () => OwnerBatch::fromModels([$owner]))->toThrow(InvalidArgumentException::class);
})->with(['unpersisted', 'missing', 'empty', 'array']);

it('bounds raw input so duplicates cannot bypass the request ceiling', function (): void {
    $owner = batchIdentityOwner(7);

    expect(fn () => OwnerBatch::fromModels(array_fill(0, 101, $owner)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => OwnerBatch::fromModels(['not-a-model']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => OwnerBatch::fromModels(['unexpected-key' => $owner]))->toThrow(InvalidArgumentException::class);
    expect(OwnerBatch::fromModels(array_map(batchIdentityOwner(...), range(1, 100)))->identities())->toHaveCount(100);
});

it('serializes numeric owner keys as object properties without collisions', function (): void {
    $owner = batchIdentityOwner(0);
    $other = new class extends Model {};
    $other->setRawAttributes(['id' => 0]);
    $other->exists = true;
    Relation::morphMap(['host.first' => $owner::class, 'host.second' => $other::class]);
    $batch = OwnerBatch::fromModels([$owner, $other]);
    $first = new ArrayObject(['visibleCount' => 2]);
    $second = new ArrayObject(['visibleCount' => 0]);
    $map = new OwnerResultMap($batch, ['host.first' => [0 => $first], 'host.second' => [0 => $second]]);
    $json = json_decode(json_encode($map, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

    expect($map->get('host.first', '0'))->toBe($first)
        ->and($map->get('host.second', '0'))->toBe($second)
        ->and($json)->toBeInstanceOf(stdClass::class)
        ->and($json->{'host.first'})->toBeInstanceOf(stdClass::class)
        ->and($json->{'host.first'}->{'0'}->visibleCount)->toBe(2)
        ->and($map->order())->toBe([['type' => 'host.first', 'id' => '0'], ['type' => 'host.second', 'id' => '0']]);
    expect(fn () => $map->get('host.first', 'absent'))->toThrow(OutOfBoundsException::class);
});

it('represents empty results as an object and rejects incomplete or foreign maps', function (): void {
    $empty = OwnerBatch::fromModels([]);
    expect($empty->isEmpty())->toBeTrue()
        ->and(json_encode(new OwnerResultMap($empty, []), JSON_THROW_ON_ERROR))->toBe('{}');
    $owner = batchIdentityOwner(7);
    $batch = OwnerBatch::fromModels([$owner]);
    expect(fn () => new OwnerResultMap($batch, []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new OwnerResultMap($empty, ['foreign' => [7 => new ArrayObject]]))->toThrow(InvalidArgumentException::class);
});

it('round trips native fully qualified morph class names as JSON properties', function (): void {
    $owner = new User;
    $owner->setRawAttributes(['id' => 7]);
    $owner->exists = true;
    $type = $owner->getMorphClass();
    $map = new OwnerResultMap(OwnerBatch::fromModels([$owner]), [$type => [7 => new ArrayObject(['visibleCount' => 0])]]);
    $json = json_decode(json_encode($map, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

    expect($type)->toBe(User::class)
        ->and($json->{$type}->{'7'}->visibleCount)->toBe(0)
        ->and($map->order())->toBe([['type' => User::class, 'id' => '7']]);
});
