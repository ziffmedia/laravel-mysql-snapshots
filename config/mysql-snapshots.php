<?php

return [
    'filesystem' => [
        'local_disk'   => 'local',
        'local_path'   => 'mysql-snapshots',
        'archive_disk' => 'cloud',
        'archive_path' => 'mysql-snapshots',
    ],

    'plans'      => [
        'daily' => [
            'connection'         => null,
            'file_template'      => 'mysql-snapshot-daily-{date:Ymd}',
            'mysqldump_options'  => '--single-transaction --no-tablespaces --set-gtid-purged=OFF --column-statistics=0',
            'schema_only_tables' => [],
            'ignore_tables'      => [],
            'keep_last'          => 1,
            'environment_locks'  => [
                'create' => 'production',
                'load'   => 'local',
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
