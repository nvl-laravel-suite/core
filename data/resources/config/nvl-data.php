<?php

declare(strict_types=1);

return ['typescript' => [
    'adopt_global_config' => false,
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
    'allowed_roots' => [base_path()],
    'source_paths' => [app_path()],
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
    'max_source_files' => 50000,
    'max_generated_files' => 2000,
    /*
    |--------------------------------------------------------------------------
    | Generated Declaration HTTP API
    |--------------------------------------------------------------------------
    |
    | These routes only serve files generated during build or deployment.
    | They never execute the transformer during a request.
    */
    'routes' => ['enabled' => false, 'prefix' => 'nvl/api/v1/data/types', 'middleware' => ['web', 'auth', 'throttle:60,1'], 'cache_control' => 'private, no-store', 'headers_prefix' => 'NVL', 'archive_enabled' => true, 'archive_name' => 'generated-types', 'archive_max_files' => 1000],
]];
