<?php

declare(strict_types=1);

use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipQueueCapture;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;
use Nvl\Support\Tests\Fixtures\CapturedOwnershipCommand;
use Nvl\Support\Tests\Fixtures\OwnedOwnershipReplacement;
use Nvl\Support\Tests\Fixtures\ReadonlyOwnershipFact;
use Nvl\Support\Tests\Fixtures\UnscopedOwnershipBase;

it('round trips only scalar versioned owner principal and generation metadata', function (): void {
    $snapshot = new OwnershipSnapshot(OwnershipContextMode::Owner, new OwnerReference('company', 'store.companies', 'string', '007'), ['kind' => 'actor', 'type' => 'actor', 'id' => '1'], generations: ['store' => 'generation']);
    $wire = OwnershipJobEnvelope::fromSnapshot($snapshot)->toArray();
    expect(OwnershipJobEnvelope::fromArray($wire)->context())->toEqual($snapshot)
        ->and(OwnershipJobEnvelope::fromArray($wire)->toArray())->toBe($wire);
    $wire['version'] = 99;
    expect(fn () => OwnershipJobEnvelope::fromArray($wire))->toThrow(OwnershipViolation::class);
});

it('never captures unresolved scope or accepts additional authority fields', function (): void {
    expect(fn () => OwnershipJobEnvelope::fromSnapshot(new OwnershipSnapshot(OwnershipContextMode::Unresolved)))->toThrow(OwnershipViolation::class);
    $wire = OwnershipJobEnvelope::capture(app(OwnershipContext::class))->toArray();
    $wire['platform'] = true;
    expect(fn () => OwnershipJobEnvelope::fromArray($wire))->toThrow(OwnershipViolation::class);
});

it('uses complete native owner identity and generation in queue lock namespaces', function (): void {
    $keys = [];
    foreach (['007', '7'] as $id) {
        foreach (['one', 'two'] as $generation) {
            $keys[] = OwnershipJobEnvelope::fromSnapshot(new OwnershipSnapshot(OwnershipContextMode::Owner, new OwnerReference('company', 'store.companies', 'string', $id), ['kind' => 'actor', 'type' => 'actor', 'id' => '1'], generations: ['store' => $generation]))->key('same-native-id');
        }
    }
    expect(array_unique($keys))->toHaveCount(4);
});

it('captures once and preserves the native identity for unowned command consumers', function (): void {
    $command = new CapturedOwnershipCommand('native-id');
    expect($command->uniqueId())->toBe('native-id')
        ->and($command->ownershipJobEnvelope()->context()->mode)->toBe(OwnershipContextMode::Disabled)
        ->and(fn () => $command->recapture())->toThrow(OwnershipViolation::class);
});

it('captures immutable scalar admission in readonly domain facts', function (): void {
    $fact = new ReadonlyOwnershipFact('committed');
    $restored = unserialize(serialize($fact), ['allowed_classes' => [ReadonlyOwnershipFact::class, OwnershipJobEnvelope::class]]);
    expect($restored)->toBeInstanceOf(ReadonlyOwnershipFact::class)
        ->and($restored->ownershipJobEnvelope()->toArray())->toBe($fact->ownershipJobEnvelope()->toArray());
});

it('permits unresolved queue capture only while every registered graph is unscoped', function (): void {
    $context = new class implements OwnershipContext
    {
        public function snapshot(): OwnershipSnapshot
        {
            return new OwnershipSnapshot(OwnershipContextMode::Unresolved);
        }

        public function requireOwner(): OwnerReference
        {
            throw new OwnershipViolation;
        }
    };
    $resources = new OwnershipResourceRegistry;
    $capture = new OwnershipQueueCapture($context, $resources);
    expect($capture->capture()->context()->mode)->toBe(OwnershipContextMode::Disabled);
    $resources->register(new OwnershipResourceDefinition('unowned.records', 'unowned', UnscopedOwnershipBase::class, OwnershipDefinition::unscoped()));
    expect($capture->capture()->context()->mode)->toBe(OwnershipContextMode::Disabled);
    $resources->register(new OwnershipResourceDefinition('owned.records', 'owned', OwnedOwnershipReplacement::class, OwnedOwnershipReplacement::ownershipDefinition()));
    expect(fn () => $capture->capture())->toThrow(OwnershipViolation::class);
});
