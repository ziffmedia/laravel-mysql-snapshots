<?php

namespace ZiffMedia\LaravelMysqlSnapshots;

use Carbon\Carbon;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class SnapshotPlan
{
    public string $name;
    public string $connection;
    public string $fileTemplate;
    public string $mysqldumpOptions = '';
    public array $schemaOnlyTables = [];
    public array $ignoreTables = [];
    public int $keepLast = 1;
    public array $environmentLocks = [];
    /** @var Collection<Snapshot> */
    public readonly Collection $snapshots;
    public readonly FilesystemAdapter $archiveDisk;
    public readonly string $archivePath;
    public readonly FilesystemAdapter $localDisk;
    public readonly string $localPath;
    protected array $fileTemplateParts;
    public static array $unacceptedFiles = [];

    /**
     * @return Collection<SnapshotPlan>
     */
    public static function all(): Collection
    {
        $snapshotPlanConfigs = config('mysql-snapshots.plans', []);

        if (count($snapshotPlanConfigs) === 0) {
            throw new \RuntimeException('mysql-snapshots.plans does not contain any configured snapshot plans');
        }

        if (isset($snapshotPlanConfigs['cached'])) {
            throw new RuntimeException('You cannot use "cached" as a plan name in your mysql-snapshots.php config');
        }

        $snapshotPlans = collect($snapshotPlanConfigs)
            ->map(fn ($config, $name) => new SnapshotPlan($name, $config));

        $archiveDisk = config('mysql-snapshots.filesystem.archive_disk') === 'cloud'
            ? Storage::cloud()
            : Storage::disk(config('mysql-snapshots.filesystem.archive_disk'));

        $archivePath = config('mysql-snapshots.filesystem.archive_path');

        foreach ($archiveDisk->allFiles($archivePath) as $archiveFile) {
            $accepted = false;

            $archiveFileName = Str::substr($archiveFile, strlen($archivePath) + 1);

            foreach ($snapshotPlans as $snapshotPlan) {
                $accepted = $snapshotPlan->accept($archiveFileName);

                if ($accepted) {
                    break;
                }
            }

            if ($accepted === false) {
                static::$unacceptedFiles[] = $archiveFile;
            }
        }

        // re-order the snapshots from latest to earliest
        foreach ($snapshotPlans as $snapshotPlan) {
            $snapshotPlan->snapshots
                ->shift(PHP_INT_MAX) // shift returns new collection here
                ->sort(fn (Snapshot $a, Snapshot $b) => $b->date->gte($a->date))
                ->each(fn (Snapshot $snapshot) => $snapshotPlan->snapshots->push($snapshot));
        }

        return $snapshotPlans;
    }

    public function __construct(string $name, array $config)
    {
        $this->name = $name;
        $this->connection = $config['connection'] ?? config('database.default');
        $this->fileTemplate = $config['file_template'] ?? 'mysql-snapshots-{date}';

        $fileTemplateString = Str::of($this->fileTemplate);

        if ($fileTemplateString->substrCount('{') > 1) {
            throw new InvalidArgumentException("file_template for Snapshot Plan $name can only contain one date replacement");
        }

        $this->fileTemplateParts['prefix'] = (string) $fileTemplateString->before('{');
        $this->fileTemplateParts['postfix'] = (string) $fileTemplateString->after('}');
        $this->fileTemplateParts['date'] = (string) $fileTemplateString->between('{', '}');

        $dateParts = explode(':', $this->fileTemplateParts['date'], 2);

        $this->fileTemplateParts['date_format'] = $dateParts[1] ?? 'Ymd';

        if (str_contains($this->fileTemplateParts['date_format'], 'W')) {
            throw new InvalidArgumentException('"W" in the date format is not supported as it cannot be used in DateTimeImmutable::createFromDate()');
        }

        if (!strpos($this->fileTemplate, '{date')) {
            throw new InvalidArgumentException("file_template for {$this->name} snapshot plan currently does not have a {date} placeholder");
        }

        $this->mysqldumpOptions = $config['mysqldump_options'] ?? '';
        $this->ignoreTables = $config['ignore_tables'] ?? [];
        $this->keepLast = (int) ($config['keep_last'] ?? 1);
        $this->environmentLocks = $config['environment_locks'] ?? ['create' => 'production', 'load' => 'local'];

        $this->snapshots = new Collection;

        $this->archiveDisk = config('mysql-snapshots.filesystem.archive_disk') === 'cloud'
            ? Storage::cloud()
            : Storage::disk(config('mysql-snapshots.filesystem.archive_disk'));

        $this->localDisk = Storage::disk(config('mysql-snapshots.filesystem.local_disk'));

        $this->archivePath = rtrim(config('mysql-snapshots.filesystem.archive_path'), '/');
        $this->localPath = rtrim(config('mysql-snapshots.filesystem.local_path'), '/');
    }

    public function getSettings()
    {
        return [
            'name'              => $this->name,
            'connection'        => $this->connection,
            'file_template'     => $this->fileTemplate,
            'mysqldump_options' => $this->mysqldumpOptions,
            'keep_last'         => $this->keepLast,
            'environment_locks' => $this->environmentLocks
        ];
    }

    public function canCreate()
    {
        return app()->environment($this->environmentLocks['create'] ?? 'production');
    }

    public function canLoad()
    {
        return app()->environment($this->environmentLocks['load'] ?? 'local');
    }

    public function create(callable $progressMessagesCallback = null)
    {
        $progressMessagesCallback = $progressMessagesCallback ?? fn () => null;

        $date = Carbon::now();
        $dateAsTitle = Str::title($date->format($this->fileTemplateParts['date_format']));

        $fileName = $this->fileTemplateParts['prefix'] . $dateAsTitle . $this->fileTemplateParts['postfix'] . '.sql';

        $mysqldumpUtil = config('mysql-snapshots.utilities.mysqldump');

        if (!$this->localDisk->exists($this->localPath)) {
            $this->localDisk->makeDirectory($this->localPath);
        }

        $localFileFullPath = $this->localDisk->path("{$this->localPath}/{$fileName}");

        $ignoreTablesOption = $this->ignoreTables ? implode(' ', array_map(fn ($table) => '--ignore-table={database}.' . $table, $this->ignoreTables)) : '';

        $schemaOnlyIgnoreTablesOption = implode(' ', array_map(fn ($table) => '--ignore-table={database}.' . $table, $this->schemaOnlyTables));
        $schemaOnlyIncludeTables = implode(' ', $this->schemaOnlyTables);

        // schema and data tables
        $command = "$mysqldumpUtil --defaults-extra-file={credentials_file} {$this->mysqldumpOptions} {$ignoreTablesOption} {$schemaOnlyIgnoreTablesOption} {database} > $localFileFullPath";

        $progressMessagesCallback('Running: ' . $command);

        $this->runCommandWithMysqlCredentials($command);

        if ($schemaOnlyIncludeTables) {
            $command = "$mysqldumpUtil --defaults-extra-file={credentials_file} {$this->mysqldumpOptions} {$ignoreTablesOption} --no-data {database} {$schemaOnlyIncludeTables} >> $localFileFullPath";

            $progressMessagesCallback('Running: ' . $command);

            $this->runCommandWithMysqlCredentials($command);
        }

        $gzipUtil = config('mysql-snapshots.utilities.gzip');

        if ($gzipUtil) {
            $command = "$gzipUtil -f $localFileFullPath";

            $progressMessagesCallback('Running: ' . $command);

            exec($command);

            // tack on .gz as that is what the above command does
            $fileName .= '.gz';
            $localFileFullPath .= '.gz';
        }

        $archiveFile = "{$this->archivePath}/$fileName";

        // store in cloud and remove from local
        $this->archiveDisk->put($archiveFile, fopen($localFileFullPath, 'r+'));
        $this->localDisk->delete("{$this->localPath}/{$fileName}");

        $snapshot = new Snapshot($fileName, $date, $this);

        // don't put in list if it matches something that was overwritten
        if (!$this->snapshots->firstWhere('fileName', $snapshot->fileName)) {
            $this->snapshots->prepend($snapshot);
        }

        return $snapshot;
    }

    public function matchFileAndDate(string $testFileName): false|Carbon
    {
        $fileName = Str::of($testFileName)->before('.');

        if (($this->fileTemplateParts['prefix'] && !$fileName->startsWith($this->fileTemplateParts['prefix']))
            || ($this->fileTemplateParts['postfix'] && !$fileName->endsWith($this->fileTemplateParts['postfix']))) {
            return false;
        }

        if (($this->fileTemplateParts['prefix'] && !$fileName->startsWith($this->fileTemplateParts['prefix']))
            || ($this->fileTemplateParts['postfix'] && !$fileName->endsWith($this->fileTemplateParts['postfix']))) {
            return false;
        }

        if (!$this->fileTemplateParts['postfix']) {
            $fileDatePart = $fileName->after($this->fileTemplateParts['prefix']);
        } elseif (!$this->fileTemplateParts['prefix']) {
            $fileDatePart = $fileName->before($this->fileTemplateParts['postfix']);
        } else {
            $fileDatePart = $fileName->betweenFirst($this->fileTemplateParts['prefix'], $this->fileTemplateParts['postfix']);
        }

        return Carbon::createFromFormat($this->fileTemplateParts['date_format'] . '|', (string) $fileDatePart);
    }

    public function accept(string $archiveFileName)
    {
        $fileDate = $this->matchFileAndDate($archiveFileName);

        if (!$fileDate) {
            return false;
        }

        $this->snapshots->push(new Snapshot($archiveFileName, $fileDate, $this));

        return true;
    }

    public function cleanupCount(): int
    {
        $copy = clone $this->snapshots;

        return $copy->splice($this->keepLast)->count();
    }

    public function cleanup(): int
    {
        return $this->snapshots->splice($this->keepLast)
            ->each(fn (Snapshot $snapshot) => $snapshot->remove())
            ->count();
    }

    public function clearCached($keepFileName = null): array
    {
        $clearedFiles = [];

        $localFiles = $this->localDisk->allFiles($this->localPath);

        foreach ($localFiles as $localFile) {
            if (!Str::startsWith($localFile, $this->localPath)) {
                continue;
            }

            $localFileName = Str::substr($localFile, strlen($this->localPath) + 1);

            if ($this->matchFileAndDate($localFileName) === false) {
                continue;
            }

            if ($keepFileName === $localFileName) {
                continue;
            }

            $clearedFiles[] = $localFileName;

            $this->localDisk->delete($localFile);
        }

        return $clearedFiles;
    }

    public function runCommandWithMysqlCredentials($command): void
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

    protected function getDatabaseConnectionConfig()
    {
        $databaseConnectionConfig = config('database.connections.' . $this->connection);

        if (!$databaseConnectionConfig) {
            throw new RuntimeException("A database connection for name {$this->connection} does not exist");
        }

        return $databaseConnectionConfig;
    }
}

