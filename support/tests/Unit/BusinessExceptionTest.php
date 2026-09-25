<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Unit;

use BackedEnum;
use Nvl\Support\Contracts\ResponseCode;
use Nvl\Support\Exceptions\BusinessException;
use Nvl\Support\Exceptions\SupportException;
use Nvl\Support\Tests\Fixtures\ExampleBusinessResponseCode;
use RuntimeException;

it('creates a message-only transport-neutral business exception', function (): void {
    $exception = new BusinessException('The operation failed.');

    expect($exception->getMessage())->toBe('The operation failed.')
        ->and($exception->responseCode())->toBeNull()
        ->and($exception->suggestedStatus())->toBe(422)
        ->and($exception->publicContext())->toBe([])
        ->and($exception->context())->toBe([]);
});

it('separates public response context from diagnostic context', function (): void {
    $previous = new RuntimeException('Database detail.');
    $exception = new BusinessException(
        message: 'The resource changed.',
        responseCode: ExampleBusinessResponseCode::Conflict,
        suggestedStatus: 409,
        publicContext: ['resource' => 'record'],
        diagnosticContext: ['revision' => 12],
        previous: $previous,
    );

    expect($exception->responseCode())->toBe('conflict')
        ->and($exception->suggestedStatus())->toBe(409)
        ->and($exception->publicContext())->toBe(['resource' => 'record'])
        ->and($exception->context())->toBe(['revision' => 12])
        ->and($exception->getPrevious())->toBe($previous);
});

it('keeps diagnostic context and exception details out of serialized public payloads', function (): void {
    $exception = new BusinessException(
        message: 'The resource changed.',
        responseCode: ExampleBusinessResponseCode::Conflict,
        suggestedStatus: 409,
        publicContext: ['resource' => 'record'],
        diagnosticContext: ['sql' => 'select * from records'],
        previous: new RuntimeException('Database detail.'),
    );

    $serializedPayload = json_encode([
        'message' => $exception->getMessage(),
        'code' => $exception->responseCode(),
        'context' => $exception->publicContext(),
    ], JSON_THROW_ON_ERROR);

    expect($serializedPayload)
        ->toBe('{"message":"The resource changed.","code":"conflict","context":{"resource":"record"}}')
        ->not->toContain('select * from records')
        ->not->toContain('Database detail.');
});

it('accepts the complete documented suggested response status range', function (int $status): void {
    expect(new BusinessException(suggestedStatus: $status))
        ->suggestedStatus()
        ->toBe($status);
})->with([100, 599]);

it('rejects invalid suggested response statuses', function (int $status): void {
    expect(fn (): BusinessException => new BusinessException(suggestedStatus: $status))
        ->toThrow(
            SupportException::class,
            'The suggested response status must be between 100 and 599.',
        );
})->with([99, 600]);

it('requires response-code implementations to be backed enums', function (): void {
    expect(is_subclass_of(ResponseCode::class, BackedEnum::class))
        ->toBeTrue()
        ->and(ExampleBusinessResponseCode::Conflict)
        ->toBeInstanceOf(BackedEnum::class);
});

it('keeps response-code values non-empty and unique', function (): void {
    $responseCodes = array_map(
        static fn (ExampleBusinessResponseCode $responseCode): string => $responseCode->value,
        ExampleBusinessResponseCode::cases(),
    );

    expect($responseCodes)
        ->toBe(array_values(array_unique($responseCodes)))
        ->and(array_filter(
            $responseCodes,
            static fn (string $responseCode): bool => $responseCode === '',
        ))
        ->toBe([]);
});
