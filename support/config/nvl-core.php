<?php

declare(strict_types=1);

return [
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
