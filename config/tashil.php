<?php

declare(strict_types=1);

use Foysal50x\Tashil\Services\Generators\InvoiceNumberGenerator;
use Foysal50x\Tashil\Services\Generators\TransactionIdGenerator;

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
            'packages'              => 'packages',
            'features'              => 'features',
            'package_feature'       => 'package_feature',
            'subscriptions'         => 'subscriptions',
            'subscription_features' => 'subscription_features',
            'feature_usages'        => 'feature_usages',
            'usage_logs'            => 'usage_logs',
            'subscription_events'   => 'subscription_events',
            'invoices'              => 'invoices',
            'transactions'          => 'transactions',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trial
    |--------------------------------------------------------------------------
    |
    | warn_days  : how many days before trial_ends_at to dispatch TrialEnding.
    | grace_days : optional grace window after trial expires (host enforces).
    |
    */
    'trial' => [
        'warn_days'  => env('TASHIL_TRIAL_WARN_DAYS', 3),
        'grace_days' => env('TASHIL_TRIAL_GRACE_DAYS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing model
    |--------------------------------------------------------------------------
    |
    | activate_on_payment      : the CREATION-TIME default for a package's
    |   `requires_payment` flag — not a runtime switch. When true (default), a
    |   new package is created with requires_payment=true, so it subscribes as
    |   `pending` and access begins only when its initial invoice is paid
    |   (strict activate-on-payment). Set false to make new packages default to
    |   the legacy "access first, bill later" model. The PACKAGE is authoritative
    |   at runtime: changing this later only affects packages created afterwards;
    |   a package explicitly marked requires_payment=true keeps gating regardless.
    |   Override per package by passing requires_payment when creating it.
    | initial_invoice_due_days : due window for the initial invoice. Shorter
    |   than renewal because a pending subscription has no access yet.
    | min_proration_amount     : skip issuing a proration invoice below this
    |   amount (avoids dust invoices on near-period-end upgrades).
    |
    */
    'billing' => [
        'activate_on_payment'      => env('TASHIL_ACTIVATE_ON_PAYMENT', true),
        'initial_invoice_due_days' => env('TASHIL_INITIAL_INVOICE_DUE_DAYS', 1),
        'min_proration_amount'     => env('TASHIL_MIN_PRORATION', 0.50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Renewal
    |--------------------------------------------------------------------------
    |
    | on_pending_invoice : policy when an unpaid invoice already exists at
    | renewal time. One of:
    |   - 'cancel'        (default; preserves the existing tashil behavior)
    |   - 'skip'          (do nothing, retry next run)
    |   - 'extend_grace'  (push current_period_end by grace_days and try again)
    | grace_days         : extension applied when policy is 'extend_grace'.
    | max_grace_extensions : cap on how many times 'extend_grace' may push the
    |   period before giving up — prevents an unpaid invoice from extending
    |   access forever.
    |
    */
    'renewal' => [
        'on_pending_invoice'   => env('TASHIL_RENEWAL_ON_PENDING_INVOICE', 'cancel'),
        'grace_days'           => env('TASHIL_RENEWAL_GRACE_DAYS', 3),
        'max_grace_extensions' => env('TASHIL_RENEWAL_MAX_GRACE_EXTENSIONS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dunning
    |--------------------------------------------------------------------------
    |
    | enabled                    : run the dunning lifecycle for unpaid
    |   invoices past their due date (tashil:process-dunning).
    | retry_days                 : days after due_date at which a dunning
    |   attempt fires (host listens to SubscriptionPastDue / InvoiceOverdue and
    |   re-charges). Each milestone increments the attempt counter.
    | suspend_after_attempts     : suspend the subscription (cut access) once
    |   the attempt count reaches this value.
    | cancel_after_suspend_days  : expire a suspended subscription this many
    |   days after suspension if still unpaid.
    | keep_access_while_past_due : whether a past_due subscription retains
    |   access during the retry window (soft dunning). Suspended never has
    |   access regardless.
    |
    */
    'dunning' => [
        'enabled'                    => env('TASHIL_DUNNING_ENABLED', true),
        'retry_days'                 => [1, 3, 5],
        'suspend_after_attempts'     => env('TASHIL_DUNNING_SUSPEND_AFTER', 3),
        'cancel_after_suspend_days'  => env('TASHIL_DUNNING_CANCEL_AFTER', 7),
        'keep_access_while_past_due' => env('TASHIL_PASTDUE_KEEP_ACCESS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler
    |--------------------------------------------------------------------------
    |
    | enabled   : set false to skip auto-registration; you'll need to wire
    |             commands manually in your app's Kernel.
    | overrides : map of command name -> cron expression that overrides the
    |             default cadence per command.
    |
    */
    'schedule' => [
        'enabled'   => env('TASHIL_SCHEDULE_ENABLED', true),
        'overrides' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | async : when true, domain events are dispatched after the DB commit
    |         using DB::afterCommit() so listeners don't see torn state.
    |
    */
    'events' => [
        'async' => env('TASHIL_EVENTS_ASYNC', true),
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
        'generator' => InvoiceNumberGenerator::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction Configuration
    |--------------------------------------------------------------------------
    |
    | Auto-generated transaction id for rows created without a gateway-
    | supplied id — e.g. when an admin records a cash payment. Same
    | format-token vocabulary as invoice numbering.
    |
    */
    'transaction' => [
        'prefix'    => 'TXN',
        'format'    => '#-YYMMDD-NNNNNNAA',
        'generator' => TransactionIdGenerator::class,
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
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | Aliases under which tahsil's three route middleware are registered
    | with the router on application boot. Set an alias to null or an
    | empty string to skip registering it (e.g. when an existing alias
    | in your host app already uses the same name).
    |
    | Usage in routes:
    |   Route::middleware('subscribed')->group(...);
    |   Route::middleware('plan:pro')->group(...);
    |   Route::middleware('feature:api-calls')->group(...);
    |
    */
    'middleware' => [
        'aliases' => [
            'subscribed' => 'subscribed',
            'plan'       => 'plan',
            'feature'    => 'feature',
        ],
    ],

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
