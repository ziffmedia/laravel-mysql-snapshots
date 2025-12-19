<?php

return [
    // Enable smart timestamp-based caching
    'cache_by_default' => false,

    // Database variant: 'mysql' or 'mariadb'
    // MariaDB's mysqldump doesn't support some MySQL-specific flags like
    // --set-gtid-purged and --column-statistics (these are automatically filtered out)
    'mysql_variant' => 'mysql',

    'filesystem' => [
        'local_disk'   => 'local',
        'local_path'   => 'mysql-snapshots',
        'archive_disk' => 'cloud',
        'archive_path' => 'mysql-snapshots',
    ],

    // Global SQL commands to run after ANY snapshot load
    'post_load_sqls' => [
        // Example: 'SET GLOBAL time_zone = "+00:00"',
        // Example: 'ANALYZE TABLE users',
    ],

    // Plan groups: Named groups of plans for batch operations
    'plan_groups' => [
        // Example:
        // 'daily' => [
        //     'plans' => ['daily-subset-1', 'daily-subset-2'],
        //     'post_load_sqls' => [
        //         // SQL commands to run after ALL plans in this group have been loaded
        //         // 'ANALYZE TABLE users',
        //     ],
        // ],
    ],

    'plans'      => [
        'daily' => [
            'connection'         => null,
            'file_template'      => 'mysql-snapshot-daily-{date:Ymd}',
            // MySQL 8.0+: '--single-transaction --no-tablespaces --set-gtid-purged=OFF --column-statistics=0'
            // MariaDB or MySQL <8.0: '--single-transaction --no-tablespaces'
            // Note: When is_mariadb is true, MySQL-only flags are automatically filtered out
            'mysqldump_options'  => '--single-transaction --no-tablespaces --set-gtid-purged=OFF --column-statistics=0',
            'schema_only_tables' => ['failed_jobs'],
            'tables'             => [],
            'ignore_tables'      => [],
            'keep_last'          => 1,
            'environment_locks'  => [
                'create' => 'production',
                'load'   => 'local',
            ],
            // Plan-specific SQL commands to run after loading this plan
            'post_load_sqls' => [
                // Example: 'UPDATE users SET environment = "local"',
            ],
        ],
    ],

    'utilities'  => [
        'mysqldump' => 'mysqldump',
        'mysql'     => 'mysql',
        'zcat'      => 'zcat',
        'gzip'      => 'gzip',
    ],
];
