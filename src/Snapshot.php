<?php

namespace ZiffMedia\LaravelMysqlSnapshots;

use Carbon\Carbon;

class Snapshot
{
    protected ?int $size = null;

    public function __construct(
        public string $fileName,
        public Carbon $date,
        protected SnapshotPlan $snapshotPlan
    ) {}

    public function existsLocally(): bool
    {
        return $this->snapshotPlan->localDisk->exists("{$this->snapshotPlan->localPath}/{$this->fileName}");
    }

    public function getSize(): int
    {
        if (!isset($this->size)) {
            $this->size = $this->snapshotPlan->archiveDisk->size(
                "{$this->snapshotPlan->archivePath}/{$this->fileName}"
            );
        }

        return $this->size;
    }

    public function getFormattedSize(): string
    {
        $bytes = $this->getSize();
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < 4; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    protected function shouldRefreshCache(): bool
    {
        if (!$this->existsLocally()) {
            return true; // No local copy, must download
        }

        // Check if there's a newer snapshot available by comparing filename dates
        $newestSnapshot = $this->snapshotPlan->snapshots->first();

        if (!$newestSnapshot) {
            return false; // No newer snapshot available
        }

        // Refresh if the newest available snapshot is newer than the current one
        return $newestSnapshot->date->gt($this->date);
    }

    protected function downloadWithProgress(callable $progressCallback): void
    {
        $archivePath = "{$this->snapshotPlan->archivePath}/{$this->fileName}";
        $localPath = "{$this->snapshotPlan->localPath}/{$this->fileName}";

        // Get total size for progress calculation
        $totalSize = $this->snapshotPlan->archiveDisk->size($archivePath);

        // Open streams
        $sourceStream = $this->snapshotPlan->archiveDisk->readStream($archivePath);

        if (!$this->snapshotPlan->localDisk->exists($this->snapshotPlan->localPath)) {
            $this->snapshotPlan->localDisk->makeDirectory($this->snapshotPlan->localPath);
        }

        $destPath = $this->snapshotPlan->localDisk->path($localPath);
        $destStream = fopen($destPath, 'w');

        $downloaded = 0;
        $bufferSize = 8192; // 8KB chunks

        while (!feof($sourceStream)) {
            $buffer = fread($sourceStream, $bufferSize);
            fwrite($destStream, $buffer);
            $downloaded += strlen($buffer);

            // Call progress callback with current progress
            $progressCallback($downloaded, $totalSize);
        }

        fclose($sourceStream);
        fclose($destStream);
    }

    public function removeLocalCopy(): void
    {
        if (!$this->existsLocally()) {
            return;
        }

        $this->snapshotPlan->localDisk->delete("{$this->snapshotPlan->localPath}/{$this->fileName}");
    }

    public function download($useLocalCopy = false, $progressCallback = null): array
    {
        $smartCache = config('mysql-snapshots.cache_by_default', false);
        $hadCachedCopy = $this->existsLocally();
        $cacheWasStale = false;

        // Honor explicit useLocalCopy flag first
        if ($useLocalCopy && $this->existsLocally() && !$this->shouldRefreshCache()) {
            return [
                'downloaded' => false,
                'cache_was_stale' => false,
                'had_cached_copy' => true,
            ];
        }

        // Smart cache: check if we need to refresh
        if ($smartCache && !$useLocalCopy && $this->existsLocally()) {
            if (!$this->shouldRefreshCache()) {
                return [
                    'downloaded' => false,
                    'cache_was_stale' => false,
                    'had_cached_copy' => true,
                ];
            }
            // Cache is stale, remove it
            $cacheWasStale = true;
            $this->removeLocalCopy();
        }

        // Download with or without progress tracking
        if ($progressCallback) {
            $this->downloadWithProgress($progressCallback);
        } else {
            $this->snapshotPlan->localDisk->put(
                "{$this->snapshotPlan->localPath}/{$this->fileName}",
                $this->snapshotPlan->archiveDisk->get("{$this->snapshotPlan->archivePath}/{$this->fileName}")
            );
        }

        return [
            'downloaded' => true,
            'cache_was_stale' => $cacheWasStale,
            'had_cached_copy' => $hadCachedCopy,
        ];
    }

    public function load($useLocalCopy = false, $keepLocalCopy = false, $progressCallback = null): array
    {
        $downloadInfo = $this->download($useLocalCopy, $progressCallback);

        $cacheInfo = [
            'downloaded' => $downloadInfo['downloaded'],
            'used_cache' => !$downloadInfo['downloaded'],
            'cache_was_stale' => $downloadInfo['cache_was_stale'],
            'had_cached_copy' => $downloadInfo['had_cached_copy'],
            'smart_cache_enabled' => config('mysql-snapshots.cache_by_default', false),
        ];

        $mysqlDumpFile = $this->snapshotPlan->localDisk->path("{$this->snapshotPlan->localPath}/{$this->fileName}");

        // utilities
        $zcatUtil = config('mysql-snapshots.utilities.zcat');
        $mysqlUtil = config('mysql-snapshots.utilities.mysql');

        $this->snapshotPlan->runCommandWithMysqlCredentials(
            "$zcatUtil $mysqlDumpFile | $mysqlUtil --defaults-extra-file={credentials_file} {database}"
        );

        // delete local
        if (!$keepLocalCopy) {
            $this->removeLocalCopy();
        }

        return $cacheInfo;
    }

    public function remove(): bool
    {
        if ($this->existsLocally()) {
            $this->removeLocalCopy();
        }

        return $this->snapshotPlan->archiveDisk->delete("{$this->snapshotPlan->archivePath}/{$this->fileName}");
    }
}
