<?php

declare(strict_types=1);

return [
    'cqrs' => [

        /*
        |--------------------------------------------------------------------------
        | Command Scan Entries
        |--------------------------------------------------------------------------
        |
        | Directories to scan for Command handler classes. Each entry may be a
        | string path or an array with 'path' and 'namespace' keys.
        |
        */

        'commands' => [
            [
                'path' => app_path('Services'),
                'namespace' => 'App\\Services\\',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Query Scan Entries
        |--------------------------------------------------------------------------
        */

        'queries' => [
            [
                'path' => app_path('Services'),
                'namespace' => 'App\\Services\\',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Infrastructure Event Scan Entries
        |--------------------------------------------------------------------------
        */

        'infrastructure_events' => [
            [
                'path' => app_path('Services'),
                'namespace' => 'App\\Services\\',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Cache File Locations
        |--------------------------------------------------------------------------
        |
        | Generated maps for commands, queries, and events are stored here.
        |
        */

        'cache' => [
            'command' => base_path('bootstrap/cache/command_map.php'),
            'query' => base_path('bootstrap/cache/query_map.php'),
            'event' => base_path('bootstrap/cache/event_map.php'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Cache Manifest Locations
        |--------------------------------------------------------------------------
        */

        'cache_manifest' => [
            'command' => base_path('bootstrap/cache/command_map_manifest.php'),
            'query' => base_path('bootstrap/cache/query_map_manifest.php'),
            'event' => base_path('bootstrap/cache/event_map_manifest.php'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Map Cache Strategy
        |--------------------------------------------------------------------------
        |
        | - enabled:           when false, maps are rebuilt on every boot.
        | - auto_refresh_env:  environments that rebuild caches automatically.
        |
        */

        'map_cache' => [
            'enabled' => filter_var($_ENV['ZOLTA_MAP_CACHE'] ?? true, FILTER_VALIDATE_BOOL),
            'auto_refresh_env' => ['local', 'testing'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Map Keys for Container Registration
        |--------------------------------------------------------------------------
        */

        'map_keys' => [
            'command' => 'command.map',
            'query' => 'query.map',
            'event' => 'event.map',
        ],

        /*
        |--------------------------------------------------------------------------
        | Scanner Options
        |--------------------------------------------------------------------------
        */

        'options' => [
            'auto_detect_psr4' => true,
            'write_atomic' => true,
            'file_pattern' => '*.php',
            'exclude_paths' => [
                '**/Persistence/Seeders/**',
                '**/Persistence/Factories/**',
                '**/Persistence/Migrations/**',
                '**/Infrastructure/Persistence/Migrations/**',
                '**/Infrastructure/Repositories/**',
                '**/API/Routes/**',
                '**/Database/**',
                '**/vendor/**',
            ],
            'composer_autoload' => base_path('vendor/autoload.php'),
            'follow_symlinks' => false,
            'verbose_logging' => false,
        ],
    ],

];
