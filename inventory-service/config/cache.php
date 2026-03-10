<?php

return [
    'default' => env('CACHE_DRIVER', 'array'),
    'stores'  => [
        'array'  => ['driver' => 'array',  'serialize' => false],
        'file'   => ['driver' => 'file',   'path' => storage_path('framework/cache/data')],
        'redis'  => [
            'driver'     => 'redis',
            'connection' => 'default',
        ],
    ],
    'prefix' => env('CACHE_PREFIX', 'inventory_service_cache'),
];
