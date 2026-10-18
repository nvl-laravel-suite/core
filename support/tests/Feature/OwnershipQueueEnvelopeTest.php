<?php

declare(strict_types=1);

use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;
use Nvl\Support\Tests\Fixtures\CapturedOwnershipCommand;

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
