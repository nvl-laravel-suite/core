<?php

declare(strict_types=1);

/** Complete runtime defaults; publication sections are declared in ../resources/config/sections.json. */
return [
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
