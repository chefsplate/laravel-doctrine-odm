<?php

return [
    
    // The name of the connection Doctrine should use (from database.php)
    'connection' => 'mongodb',

    /*
    |--------------------------------------------------------------------------
    | Doctrine Document Mapper Settings
    |--------------------------------------------------------------------------
    |
    | Configure the Doctrine ODM settings here. Change the
    | paths setting to the appropriate path and replace App namespace
    | by your own namespace.
    |
    | --> Warning: Proxy auto generation should only be enabled in dev!
    |
    */

    'doctrine_dm' => [

        /* A list of entities */
        'paths' => [
            base_path('app/Entities')
        ],

        'proxies' => [
            'namespace'     => 'Proxies',
            'path'          => storage_path('proxies'),
            'auto_generate' => env('DOCTRINE_PROXY_AUTOGENERATE', false)
        ],

        'hydrators' => [
            'namespace' => 'Hydrators',
            'path'      => storage_path('hydrators'),
        ],

        // TODO: support multiple metadata implementations
        'meta'    => env('DOCTRINE_METADATA', 'annotations'),

    ],

    // Document Mapper Settings specific to our Laravel implementation
    'laravel_dm' => [

        'soft_deletes' => [
            'enabled'    => true,
            'field_name' => 'deleted_at'
        ]

    ],
];
