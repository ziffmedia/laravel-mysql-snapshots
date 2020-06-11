<?php

namespace ZiffMedia\LaravelMysqlSnapshots;

use Illuminate\Support\Facades\Storage;

class SnapshotPlan
{
    protected $name;
    protected $connection;
    protected $fileTemplate;
    protected $mysqldumpOptions;
    protected $ignoreTables;
    protected $keep = 1;
    protected $environmentLocks = [];

    /**
     * @return \Illuminate\Support\Collection|Snapshot[]
     */
    public static function all()
    {
        return collect(config('mysql-snapshots.plans'))->map(function ($config, $name) {
            return new SnapshotPlan($name, $config);
        });
    }

    public function __construct(string $name, array $config)
    {
        $this->name = $name;
        $this->connection = $config['connection'] ?? 'default';
        $this->fileTemplate = $config['file_template'] ?? 'mysql-snapshots-{date|YMDHi}';
        $this->mysqldumpOptions = $config['mysqldump_options'] ?? '';
        // $this->ignoreTables = $config['ignore_tables'] ?? '';
        $this->keep = $config['keep'] ?? 1;
        $this->environmentLocks = $config['environment_locks'] ?? ['create' => 'production', 'load' => 'local'];
    }

    public function canCreate()
    {
        return app()->environment($this->environmentLocks['create']);
    }

    public function canLoad()
    {
        return app()->environment($this->environmentLocks['load']);
    }

    public function create(): Snapshot
    {
        $fileName = $this->createFileName();

        $localFilePath = config('mysql-snapshots.filesystem.local_path');

        $mysqldumpUtil = config('mysql-snapshots.utilities.mysqldump');

        $this->runCommandWithCredentials(
            "$mysqldumpUtil --defaults-extra-file={credentials_file} {$this->mysqldumpOptions} {database} > {$localFilePath}/{$fileName}"
        );

        $gzipUtil = config('mysql-snapshots.utilities.gzip');

        exec("$gzipUtil -f {$localFilePath}/{$fileName}");

        // tack on .gz as that is what the above command does
        $fileName = $fileName . '.gz';

        $archiveFilePath = config('mysql-snapshots.filesystem.archive_path');

        // move to archive
        Storage::disk(config('mysql-snapshots.filesystem.archive_disk'))->put(
            "$archiveFilePath/$fileName",
            fopen("$localFilePath/$fileName", 'r+')
        );

        unlink("{$localFilePath}/{$fileName}");
    }

    public function cleanup(): void
    {
        $files = Storage::disk(config('mysql-snapshots.filesystem.archive_disk'))->allFiles(config('mysql-snapshots.filesystem.archive_path'));
    }

    public function load(): void
    {
        // get all files
        $files = Storage::disk(config('mysql-snapshots.filesystem.archive_disk'))->allFiles(config('mysql-snapshots.filesystem.archive_path'));

        dd($files);
    }

    protected function getDatabaseConnectionConfig()
    {
        $databaseConnectionConfig = config('database.connections' . $this->connection);

        if (!$databaseConnectionConfig) {
            throw new \RuntimeException('A database connection for name ' . $this->connection . ' does not exist');
        }

        return $databaseConnectionConfig;
    }

    protected function runCommandWithCredentials($command)
    {
        $dbConfig = $this->getDatabaseConnectionConfig();

        $disk = Storage::disk('local'); // fix

        $dbHost = $dbConfig['read']['host'][0] ?? $dbConfig['host'];

        $disk->put('mysql-credentials.txt', implode(PHP_EOL, [
            '[client]',
            "user = '{$dbConfig['username']}'",
            "password = '{$dbConfig['password']}'",
            "host = '{$dbHost}'",
            "port = '{$dbConfig['port']}'",
        ]));

        $command = str_replace(
            ['{credentials_file}', '{database}'],
            [$disk->path('mysql-credentials.txt'), $dbConfig['database']],
            $command
        );

        exec($command);

        $disk->delete('mysql-credentials.txt');
    }

    protected function createFileName()
    {
        // @todo make this smarter
        return str_replace(
            ['{date|YMDHi}'],
            [date('YMDHi')],
            $this->fileTemplate
        ) . '.sql';
    }
}

