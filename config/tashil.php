<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the database settings for Tashil tables.
    | You can specify a separate connection, table prefix, and customize
    | the table names used by the package to avoid conflicts.
    |
    */
    'database' => [
        // The database connection to use for Tashil tables.
        // If null, the default application connection will be used.
        // You can define a separate connection in config/database.php.
        'connection' => env('TASHIL_DB_CONNECTION', null),

        // Table prefix for all Tashil tables.
        // This prefix will be prepended to the table names defined below.
        // Default is 'tashil_' to namespace package tables.
        'prefix' => 'tashil_',

        // Customise table names used by the package.
        // Keys represent the internal reference, values are the actual table names (without prefix).
        // Change these only if you have naming conflicts in your database.
        'tables' => [
            'packages'           => 'packages',
            'features'           => 'features',
            'package_feature'    => 'package_feature',
            'subscriptions'      => 'subscriptions',
            'subscription_items' => 'subscription_items',
            'usage_logs'         => 'usage_logs',
            'invoices'           => 'invoices',
            'transactions'       => 'transactions',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for automated invoice number generation.
    |
    | Available Tokens for Format:
    | - #  : Prefix (defined below)
    | - YY : Year (2 digits)
    | - MM : Month (2 digits)
    | - DD : Day (2 digits)
    | - N  : Random Digit (0-9)
    | - S  : Random Letter (A-Z)
    | - A  : Random Alphanumeric (A-Z0-9)
    |
    | Example: '#-YYMMDD-NNNNNN' -> 'INV-231125-849201'
    |
    */
    'invoice' => [
        'prefix'    => 'INV',
        'format'    => '#-YYMMDD-NNNNNN', // #=Prefix, YY/MM/DD=Date, N=Digit, S=Letter, A=AlphaNumeric
        'generator' => Foysal50x\Tashil\Services\Generators\InvoiceNumberGenerator::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | The default currency code (ISO 4217) used for new packages.
    | This is used when creating packages and generating invoices.
    | Examples: 'USD', 'EUR', 'GBP', 'AED'.
    |
    */
    'currency' => env('TASHIL_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the separate Redis connection used by Tashil.
    | This is used for high-performance caching of subscription status
    | and feature usage tracking to reduce database load.
    |
    */
    'redis' => [
        'host'     => env('TASHIL_REDIS_HOST', env('REDIS_HOST', '127.0.0.1')),
        'password' => env('TASHIL_REDIS_PASSWORD', env('REDIS_PASSWORD', null)),
        'port'     => env('TASHIL_REDIS_PORT', env('REDIS_PORT', 6379)),
        'database' => env('TASHIL_REDIS_DB', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the caching layer. You can customise the prefix
    | to avoid collisions with other cache keys in your application.
    | The TTL settings control how long data remains cached.
    |
    */
    'cache' => [
        'prefix' => env('TASHIL_CACHE_PREFIX', 'tashil_cache:'),

        // Default Time To Live (TTL) in seconds for cached items.
        // 3600 = 1 hour. Set to 0 to disable caching (not recommended).
        'ttl' => 3600,

        // Whether to enable the caching layer globally.
        // Useful for debugging or local development.
        'enabled' => env('TASHIL_CACHE_ENABLED', true),
    ],
];
