<?php

declare(strict_types=1);

use Nvl\Support\Testing\FakeCall;
use Nvl\Support\Testing\FakeExpectationFailed;
use Nvl\Support\Testing\UnscriptedFakeCall;
use Nvl\Support\Tests\Fixtures\ScriptedFake;

test('scripted responses are FIFO per method and preserve failed attempts', function (): void {
    $failure = new RuntimeException('explicit failure');
    $fake = (new ScriptedFake)->willReturn('read', 'first')->willThrow('read', $failure)->willReturn('read', 'last')->willReturn('write', null);

    expect($fake->read('integer-42'))->toBe('first');
    $fake->write('integer-42', ['opaque' => true]);
    try {
        $fake->read('second');
        test()->fail('The exact scripted exception must propagate.');
    } catch (RuntimeException $exception) {
        expect($exception)->toBe($failure);
    }
    expect($fake->read('third'))->toBe('last')
        ->and(fn (): mixed => $fake->read('fourth'))->toThrow(UnscriptedFakeCall::class)
        ->and(array_map(static fn (FakeCall $call): string => $call->method, $fake->calls()))->toBe(['read', 'write', 'read', 'read', 'read'])
        ->and($fake->calls('read')[3]->arguments)->toBe(['identity' => 'fourth']);
    $fake->assertCalled('read', times: 4);
    $fake->assertCalled('write', static fn (FakeCall $call): bool => $call->arguments['value'] === ['opaque' => true]);
});

test('script values remain values and histories belong to one fake instance', function (): void {
    $executed = false;
    $closure = static function () use (&$executed): void {
        $executed = true;
    };
    $first = (new ScriptedFake)->willReturn('read', $closure);
    $second = new ScriptedFake;

    expect($first->read('one'))->toBe($closure)
        ->and($executed)->toBeFalse()
        ->and($second->calls())->toBe([])
        ->and(fn (): mixed => $second->read('one'))->toThrow(UnscriptedFakeCall::class);
    $first->assertCalled('read', static fn (FakeCall $call): bool => $call->arguments['identity'] === 'absent', times: 0);
    expect(fn (): mixed => $first->assertCalled('read', times: 0))->toThrow(FakeExpectationFailed::class)
        ->and(fn (): mixed => $first->assertCalled('read', times: -1))->toThrow(FakeExpectationFailed::class);
});

test('unsupported fake methods fail before scripts or expectations change', function (string $method): void {
    $fake = new ScriptedFake;

    expect(fn (): mixed => $fake->willReturn($method, 'bad'))->toThrow(FakeExpectationFailed::class)
        ->and(fn (): mixed => $fake->willThrow($method, new RuntimeException))->toThrow(FakeExpectationFailed::class)
        ->and(fn (): mixed => $fake->calls($method))->toThrow(FakeExpectationFailed::class)
        ->and(fn (): mixed => $fake->assertCalled($method))->toThrow(FakeExpectationFailed::class)
        ->and($fake->calls())->toBe([]);
})->with(['unknown', 'Read', '']);
