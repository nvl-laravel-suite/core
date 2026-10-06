<?php

declare(strict_types=1);

return [
    'compatibility' => [
        'legacy_config' => [],
        'legacy_env' => env('NVL_CORE_LEGACY_ENV', false),
        'global_aliases' => [],
        'legacy_routes' => [],
    ],
    'migrations' => ['published' => []],
    'connection' => null,
    'queue' => ['connection' => null, 'name' => null],
    'locks' => ['store' => null],
    'routes' => ['middleware' => null],
    'authorization' => ['guard' => null],
    'owners' => [],
    'locales' => [
        'supported' => null,
        'default' => null,
        'fallback' => null,
    ],
];
