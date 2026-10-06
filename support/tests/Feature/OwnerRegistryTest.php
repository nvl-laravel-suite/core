<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Support\OwnerRegistry;

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

it('shares canonical identities without enforcing unrelated host models', function (): void {
    $owner = new class extends Model {};
    $other = new class extends Model {};
    Relation::morphMap(['host-model' => $other::class]);
    $registry = new OwnerRegistry(new Repository(['nvl-core' => ['owners' => ['article' => $owner::class]]]));

    $registry->register('article', $owner::class);
    $registry->register('article', $owner::class);

    expect($registry->model('article'))->toBe($owner::class)
        ->and($registry->aliasFor($owner))->toBe('article')
        ->and($owner->getMorphClass())->toBe('article')
        ->and(Relation::getMorphedModel('host-model'))->toBe($other::class)
        ->and(Relation::requiresMorphMap())->toBeFalse();
});

it('rejects identity conflicts before changing the host morph map', function (string $conflict): void {
    $owner = new class extends Model {};
    $other = new class extends Model {};
    Relation::morphMap($conflict === 'alias' ? ['article' => $other::class] : ['old-article' => $owner::class]);
    $original = Relation::morphMap();
    $registry = new OwnerRegistry(new Repository);

    expect(fn () => $registry->register('article', $owner::class))->toThrow(InvalidArgumentException::class)
        ->and(Relation::morphMap())->toBe($original);
})->with(['alias', 'model']);

it('validates an entire canonical map before adding any aliases', function (): void {
    $owner = new class extends Model {};
    $other = new class extends Model {};
    Relation::morphMap(['occupied' => $other::class]);
    $registry = new OwnerRegistry(new Repository(['nvl-core' => ['owners' => [
        'article' => $owner::class,
        'occupied' => $owner::class,
    ]]]));

    expect(fn () => $registry->all())->toThrow(InvalidArgumentException::class)
        ->and(Relation::getMorphedModel('article'))->toBeNull();
});

it('keeps legacy class-backed identities from silently switching write types', function (): void {
    $owner = new class extends Model {};
    $registry = new OwnerRegistry(new Repository);

    expect($registry->reference($owner::class, 'seo.owners.article', 'article'))->toBe($owner::class)
        ->and($registry->aliasFor($owner))->toBe('article')
        ->and($owner->getMorphClass())->toBe($owner::class)
        ->and(Relation::getMorphedModel('article'))->toBeNull();

    $registry->reference($owner::class, 'seo.owners.article', 'article');

    expect($registry->deprecations())->toHaveCount(1);
});

it('retains legacy mapped aliases and rejects conflicting canonical references', function (): void {
    $owner = new class extends Model {};
    $other = new class extends Model {};
    $registry = new OwnerRegistry(new Repository(['nvl-core' => ['owners' => ['article' => $owner::class]]]));

    expect($registry->reference($owner::class, 'content.owners.article', 'article', true))->toBe($owner::class)
        ->and($owner->getMorphClass())->toBe('article')
        ->and(fn () => $registry->reference($other::class, 'seo.owners.article', 'article'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a second canonical alias for a legacy class-backed owner', function (): void {
    $owner = new class extends Model {};
    $registry = new OwnerRegistry(new Repository);
    $registry->reference($owner::class, 'seo.owners.article', 'article');

    expect(fn () => $registry->register('different', $owner::class))->toThrow(InvalidArgumentException::class)
        ->and($owner->getMorphClass())->toBe($owner::class);
});

it('rejects unknown aliases and invalid identity declarations', function (): void {
    $registry = new OwnerRegistry(new Repository);

    expect(fn () => $registry->model('unknown'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('', Model::class))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('article', stdClass::class))->toThrow(InvalidArgumentException::class);
});

it('identifies existing non Eloquent classes as invalid model declarations', function (): void {
    $registry = new OwnerRegistry(new Repository);

    expect(fn () => $registry->reference(stdClass::class, 'seo.owners.article', 'article'))
        ->toThrow(InvalidArgumentException::class, 'must be an Eloquent model class');
});

it('resolves explicitly declared aliases before interpreting existing class names', function (): void {
    $owner = new class extends Model {};
    $registry = new OwnerRegistry(new Repository(['nvl-core' => ['owners' => ['stdClass' => $owner::class]]]));

    expect($registry->reference('stdClass', 'seo.owners.article'))->toBe($owner::class)
        ->and($registry->deprecations())->toBe([]);
});

it('rejects numeric only aliases consistently before adding a morph mapping', function (): void {
    $owner = new class extends Model {};
    $registry = new OwnerRegistry(new Repository);

    expect(fn () => $registry->register('123', $owner::class))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->reference($owner::class, 'seo.owners.123', '123'))->toThrow(InvalidArgumentException::class)
        ->and(Relation::getMorphedModel('123'))->toBeNull();

    $configured = new OwnerRegistry(new Repository(['nvl-core' => ['owners' => ['123' => $owner::class]]]));
    expect(fn () => $configured->all())->toThrow(InvalidArgumentException::class);
});
