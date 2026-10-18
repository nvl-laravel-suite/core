<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

/** Fixed protocol identities survive runtime removal and configuration replacement. @internal */
final class OwnershipStorageTables
{
    public const string Stores = 'nvl_ownership_stores';

    public const string Runs = 'nvl_ownership_adoption_runs';

    public const string Checkpoints = 'nvl_ownership_adoption_checkpoints';

    public const string Markers = 'nvl_ownership_resource_markers';

    public const string Mappings = 'nvl_ownership_legacy_owner_mappings';

    public const string Operations = 'nvl_ownership_operation_facts';

    private function __construct() {}
}
