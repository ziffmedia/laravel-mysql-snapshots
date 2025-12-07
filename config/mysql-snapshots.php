<?php

return [
    'filesystem' => [
        'local_disk'       => 'local',
        'local_path'       => 'mysql-snapshots',
        'archive_disk'     => 'cloud',
        'archive_path'     => 'mysql-snapshots',
        'cache_by_default' => false, // Enable smart timestamp-based caching
    ],

    // Global SQL commands to run after ANY snapshot load
    'post_load_commands' => [
        // Example: 'SET GLOBAL time_zone = "+00:00"',
        // Example: 'ANALYZE TABLE users',
    ],

    // Plan groups: Named groups of plans for batch operations
    'plan_groups' => [
        // Example:
        // 'daily' => [
        //     'plans' => ['daily-subset-1', 'daily-subset-2'],
        // ],
    ],

    'plans'      => [
        'daily' => [
            'connection'         => null,
            'file_template'      => 'mysql-snapshot-daily-{date:Ymd}',
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
            'post_load_commands' => [
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
