<?php

namespace ZiffMedia\LaravelMysqlSnapshots;

use Carbon\Carbon;

class Snapshot
{
    public function __construct(
        public string $fileName,
        public Carbon $date,
        protected SnapshotPlan $snapshotPlan
    ) {
    }

    public function existsLocally(): bool
    {
        return $this->snapshotPlan->localDisk->exists("{$this->snapshotPlan->localPath}/{$this->fileName}");
    }

    public function removeLocalCopy(): void
    {
        if (!$this->existsLocally()) {
            return;
        }

        $this->snapshotPlan->localDisk->delete("{$this->snapshotPlan->localPath}/{$this->fileName}");
    }

    public function download($useLocalCopy = false): bool
    {
        if ($useLocalCopy && $this->existsLocally()) {
            $this->info('Using local snapshot');

            return false;
        }

        $archiveFile = "{$this->snapshotPlan->archivePath}/{$this->fileName}";

        $fileSize = round($this->snapshotPlan->archiveDisk->getSize($archiveFile) / 1024 / 1024) . 'MB';

        $this->info("Downloading remote snapshot ($fileSize)..");
        $this->snapshotPlan->localDisk->put(
            "{$this->snapshotPlan->localPath}/{$this->fileName}",
            $this->snapshotPlan->archiveDisk->get($archiveFile)
        );

        return true;
    }

    public function load($useLocalCopy = false, $keepLocalCopy = false): void
    {
        $this->download($useLocalCopy);

        $mysqlDumpFile = $this->snapshotPlan->localDisk->path("{$this->snapshotPlan->localPath}/{$this->fileName}");

        // utilities
        $zcatUtil = config('mysql-snapshots.utilities.zcat');
        $mysqlUtil = config('mysql-snapshots.utilities.mysql');

        $this->info('Running SQL commands');
        $this->snapshotPlan->runCommandWithMysqlCredentials(
            "$zcatUtil $mysqlDumpFile | $mysqlUtil --defaults-extra-file={credentials_file} {database}"
        );

        // delete local
        if (!$keepLocalCopy) {
            $this->removeLocalCopy();
        }
    }

    public function remove(): bool
    {
        if ($this->existsLocally()) {
            $this->removeLocalCopy();
        }

        return $this->snapshotPlan->archiveDisk->delete("{$this->snapshotPlan->archivePath}/{$this->fileName}");
    }
}
