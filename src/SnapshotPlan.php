<?php

namespace ZiffMedia\LaravelMysqlSnapshots;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

class SnapshotPlan
{
    public $name;
    public $connection;
    public $fileTemplate;
    public $mysqldumpOptions;
    public $ignoreTables;
    public $keep = 1;
    public $environmentLocks = [];

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

        $this->connection = $config['connection']
            ? $config['connection']
            : config('database.default');

        $this->fileTemplate = $config['file_template'] ?? 'mysql-snapshots-{date|YMDHi}';

        if (strpos($this->fileTemplate, '.')) {
            throw new \InvalidArgumentException("file_template for {$this->name} snapshot plan cannot contain a '.'");
        }

        if (strpos($this->fileTemplate, '{')) {
            throw new \InvalidArgumentException("file_template for {$this->name} snapshot plan currently does not support replacements");
        }

        $this->mysqldumpOptions = $config['mysqldump_options'] ?? '';

        // @todo
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

    public function create()
    {
        $fileName = $this->createFileName();

        $localFilePath = config('mysql-snapshots.filesystem.local_path');

        $mysqldumpUtil = config('mysql-snapshots.utilities.mysqldump');

        $localFs = Storage::disk(config('mysql-snapshots.filesystem.local_disk'));

        if (!$localFs->exists($localFilePath)) {
            $localFs->makeDirectory($localFilePath);
        }

        $mysqlDumpFile = $localFs->path("$localFilePath/$fileName");

        $this->runCommandWithCredentials(
            "$mysqldumpUtil --defaults-extra-file={credentials_file} {$this->mysqldumpOptions} {database} > {$mysqlDumpFile}"
        );

        $gzipUtil = config('mysql-snapshots.utilities.gzip');

        exec("$gzipUtil -f $mysqlDumpFile");

        // tack on .gz as that is what the above command does
        $fileName = $fileName . '.gz';
        $mysqlDumpFile = $mysqlDumpFile . '.gz';

        $archiveFilePath = config('mysql-snapshots.filesystem.archive_path');

        $archiveFs = $this->getArchiveFilesystem();

        $archiveFs->put(
            "$archiveFilePath/$fileName",
            fopen($mysqlDumpFile, 'r+')
        );

        unlink($mysqlDumpFile);
    }

    public function cleanup(): void
    {
        // $files = Storage::disk(config('mysql-snapshots.filesystem.archive_disk'))->allFiles(config('mysql-snapshots.filesystem.archive_path'));
    }

    public function load(): void
    {
        $fileName = $this->list()->first();

        // copy file down if necessary
        $archiveFilePath = config('mysql-snapshots.filesystem.archive_path');

        $archiveFs = $this->getArchiveFilesystem();

        $localFilePath = config('mysql-snapshots.filesystem.local_path');

        /**
         * @todo this is where caching and content checking should be taking place
         */

        $localFs = Storage::disk(config('mysql-snapshots.filesystem.local_disk'));

        if (!$localFs->exists($localFilePath)) {
            $localFs->makeDirectory($localFilePath);
        }

        $mysqlDumpFile = $localFs->path("$localFilePath/$fileName");

        $localFs->put(
            "$localFilePath/$fileName",
            $archiveFs->get("$archiveFilePath/$fileName")
        );

        $zcatUtil = config('mysql-snapshots.utilities.zcat');

        $mysqlUtil = config('mysql-snapshots.utilities.mysql');

        $this->runCommandWithCredentials(
            "$zcatUtil $mysqlDumpFile | $mysqlUtil --defaults-extra-file={credentials_file} {database}"
        );
    }

    public function list()
    {
        $archiveFs = $this->getArchiveFilesystem();

        $archiveFilePath = config('mysql-snapshots.filesystem.archive_path');

        return collect($archiveFs->allFiles())
            ->map(function ($file) use ($archiveFilePath) {
                if (strpos($file, $archiveFilePath) !== 0) {
                    return false;
                }

                $file = substr($file, strlen($archiveFilePath) + 1); // including /

                [$filename, $extension] = explode('.', $file, 2);

                if ($filename == $this->fileTemplate) {
                    return $file;
                }

                return false;
            })
            ->filter()
            ->values();
    }

    /**
     * @return \Illuminate\Contracts\Filesystem\Filesystem
     */
    protected function getArchiveFilesystem()
    {
        $archiveDiskName = config('mysql-snapshots.filesystem.archive_disk');

        if (config("filesystems.disks.$archiveDiskName")) {
            return Storage::disk($archiveDiskName);
        }

        if ($archiveDiskName === 'cloud') {
            return Storage::cloud();
        }

        throw new \RuntimeException("$archiveDiskName is not a valid filesystem disk");
    }

    protected function getDatabaseConnectionConfig()
    {
        $databaseConnectionConfig = config('database.connections.' . $this->connection);

        if (!$databaseConnectionConfig) {
            throw new \RuntimeException('A database connection for name ' . $this->connection . ' does not exist');
        }

        return $databaseConnectionConfig;
    }

    protected function runCommandWithCredentials($command)
    {
        $dbConfig = $this->getDatabaseConnectionConfig();

        $disk = Storage::disk('local'); // @todo fix this: is there always a local filesystem named local?

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
            ['{date|YmdHi}', '{plan_name}'],
            [date('YmdHi'), $this->name],
            $this->fileTemplate
        ) . '.sql';
    }
}

