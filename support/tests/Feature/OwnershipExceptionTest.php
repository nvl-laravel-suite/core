<?php

declare(strict_types=1);

use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

it('exposes neutral ownership denial metadata using the existing package failure contract', function (): void {
    $exception = new OwnershipViolation;
    expect($exception)->toBeInstanceOf(RespondableException::class)
        ->and($exception->package())->toBe('ownership')
        ->and($exception->responseCode())->toBe('ownership_boundary_violation')
        ->and($exception->suggestedStatus())->toBe(404)
        ->and($exception->publicContext())->toBe([]);
});

it('keeps configuration failures distinguishable from authorization denial', function (): void {
    $exception = new OwnershipConfigurationInvalid('Invalid deployment mapping.');
    expect($exception)->toBeInstanceOf(RespondableException::class)
        ->and($exception->responseCode())->toBe('ownership_configuration_invalid')
        ->and($exception->suggestedStatus())->toBe(500)
        ->and($exception->publicContext())->toBe([]);
});
