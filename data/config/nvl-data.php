<?php

declare(strict_types=1);

return [
    'typescript' => [
        /*
        |--------------------------------------------------------------------------
        | Transformer Configuration
        |--------------------------------------------------------------------------
        |
        | Disable this when the host application binds its own
        | TypeScriptTransformerConfig. Source paths may also be registered at
        | runtime through TypeScriptSourceRegistry.
        */
        'configure_transformer' => true,
        'allowed_roots' => [
            base_path(),
        ],
        'source_paths' => [
            app_path(),
        ],
        'output_directory' => resource_path('js/types'),
        'output_file' => 'generated.d.ts',
        'manifest_file' => 'generated.manifest.json',
        'enum_union_types' => true,
        'writer' => 'split',
        'split_directory' => 'generated',
        'scope_mappings' => [],
        'model_type' => 'any',
        'readonly_properties' => false,
        'type_replacements' => [],
        'memory_limit' => '1G',
        'max_source_files' => 50_000,
        'max_generated_files' => 2_000,
        'max_generated_bytes' => 100 * 1024 * 1024,
        'max_manifest_bytes' => 5 * 1024 * 1024,

        /*
        |--------------------------------------------------------------------------
        | Generated Declaration HTTP API
        |--------------------------------------------------------------------------
        |
        | These routes only serve files generated during build or deployment.
        | They never execute the transformer during a request.
        */
        'routes' => [
            'enabled' => false,
            'prefix' => 'api/v1/nvl/types',
            'middleware' => ['web', 'auth', 'throttle:60,1'],
            'cache_control' => 'private, no-store',
            'headers_prefix' => 'NVL',
            'archive_enabled' => true,
            'archive_name' => 'generated-types',
            'archive_max_bytes' => 25 * 1024 * 1024,
            'archive_max_files' => 1_000,
        ],
    ],
];
