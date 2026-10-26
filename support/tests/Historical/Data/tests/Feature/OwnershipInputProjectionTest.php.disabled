<?php

declare(strict_types=1);

use Nvl\Data\Tests\Fixtures\OwnershipProjectionData;

test('undeclared ownership input is absent from persistence projection', function (string $ownershipField): void {
    $data = OwnershipProjectionData::from(['name' => 'safe', $ownershipField => 'forged']);

    expect($data->toModelFiltered())->toBe(['name' => 'safe']);
})->with(['tenantId', 'tenant_id', 'ownershipKey', 'ownership_key']);
