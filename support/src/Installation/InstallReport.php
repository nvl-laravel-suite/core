<?php

declare(strict_types=1);

namespace Nvl\Support\Installation;

use Nvl\Support\Bindings\RequiredBindingStatus;

/** Records configuration publication and metadata-only binding guidance. @api */
final readonly class InstallReport
{
    /**
     * Retain exact selected resources and capability status without executing adapters.
     *
     * @param  list<string>  $packages
     * @param  list<array{path: string, result: 'published'|'preserved'|'would_publish'|'would_replace', backup: ?string}>  $configuration
     * @param  list<RequiredBindingStatus>  $bindings
     */
    public function __construct(
        public array $packages,
        public array $configuration,
        public array $bindings,
        public bool $missingEnabledBindings,
    ) {}

    /**
     * Return the versioned command report with command-only adapter diagnostics.
     *
     * @return array{schema_version: int, packages: list<string>, configuration: list<array{path: string, result: string, backup: ?string}>, bindings: list<array{package: string, contract: string, capability: string, status: string, message: string, documentation: string}>, healthy: bool}
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'packages' => $this->packages,
            'configuration' => $this->configuration,
            'bindings' => array_map(static fn (RequiredBindingStatus $status): array => [
                'package' => $status->package, 'contract' => $status->contract,
                'capability' => $status->capability, 'status' => $status->status,
                'message' => $status->message, 'documentation' => $status->documentation,
            ], $this->bindings),
            'healthy' => ! $this->missingEnabledBindings,
        ];
    }
}
