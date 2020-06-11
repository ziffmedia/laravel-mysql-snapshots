<?php

return [
    'filesystem' => [
        'local_disk'   => 'local', // is this necessary?
        'local_path'   => 'mysql-snapshots',
        'archive_disk' => 'cloud',
        'archive_path' => 'mysql-snapshots',
    ],

    'plans' => [
        'daily' => [
            'connection'        => 'default',
            'file_template'     => 'mysql-snapshot-{plan_name}-{date|YMDHi}.{extension}',
            'mysqldump_options' => '--single-transaction',
            // 'ignore_tables'     => '',
            'keep_last'         => 1,
            'environment_locks' => [
                'create' => 'production',
                'load'   => 'local'
            ]
        ]
    ],

    'utilities' => [
        'mysqldump' => 'mysqldump',
        'mysql'     => 'mysql',
        'zcat'      => 'zcat',
        'gzip'      => 'gzip'
    ]
];
