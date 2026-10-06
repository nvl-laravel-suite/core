<?php

declare(strict_types=1);

/** Complete runtime defaults; publication sections are declared in ../resources/config/sections.json. */
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
    'logging' => [
        'channel' => 'nvl',
        'verbosity' => 'normal',
        'packages' => ['csv' => ['verbosity' => 'quiet']],
    ],
    'owners' => [],
    'locales' => [
        'supported' => null,
        'default' => null,
        'fallback' => null,
    ],
];
