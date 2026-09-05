<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Audit Driver
    |--------------------------------------------------------------------------
    |
    | The driver used to persist audit entries. Supported: "database", "memory".
    | In production, use "database". "memory" is for tests and lightweight hosts.
    |
    */

    'driver' => env('AUDIT_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    |
    | Connection and table for the database driver. If connection is null,
    | the application's default connection is used.
    |
    */

    'database' => [
        'connection' => env('AUDIT_DB_CONNECTION'),
        'table' => env('AUDIT_DB_TABLE', 'audit_entries'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Failure Policy
    |--------------------------------------------------------------------------
    |
    | Default policy when audit append fails. Can be overridden per-operation
    | in the 'operations' array below.
    |
    | Supported values: "fail_open", "fail_closed".
    |
    */

    'failure_policy' => [
        'default' => env('AUDIT_FAILURE_DEFAULT', 'fail_open'),

        // Per-operation overrides (operation string => policy value)
        'operations' => [
            // 'access.grant.created' => 'fail_closed',
            // 'bot.token.rotated' => 'fail_closed',
            // 'bot.deleted' => 'fail_closed',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How many days to keep audit entries. The audit:prune command uses this
    | value. Set to 0 to disable automatic pruning.
    |
    */

    'retention' => [
        'days' => (int) env('AUDIT_RETENTION_DAYS', 365),
    ],

];
