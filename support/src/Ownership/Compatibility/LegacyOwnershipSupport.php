<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Compatibility;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Exceptions\BusinessException;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;

/** Contains temporary legacy classification while domain packages consume native ownership APIs. @internal */
final readonly class LegacyOwnershipSupport
{
    /** Resolve transient compatibility services without retaining a scope in shared metadata. */
    public function __construct(private Repository $configuration, private TenantResourceRegistry $resources, private TenantContext $context) {}

    /** Read the explicitly selected legacy runtime without retaining request state. */
    public function enabled(): bool
    {
        return $this->configuration->get('nvl-tenancy.enabled') === true;
    }

    /** Resolve a model through the explicitly registered legacy graph. */
    public function resource(Model $model): string
    {
        return $this->resources->forModel($model)->key;
    }

    /** Preserve the legacy exception contract within the compatibility window. */
    public function violation(string $message): BusinessException
    {
        return new TenantBoundaryViolation($message);
    }

    /** Preserve historical capability bytes without granting native admission. */
    public function capability(): ?string
    {
        $snapshot = $this->context->snapshot();

        return $snapshot->mode === TenantContextMode::Disabled ? null : hash('sha256', $snapshot->mode->value."\0".($snapshot->tenantId !== null ? $snapshot->tenantId->value : ''));
    }
}
