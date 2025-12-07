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

    protected function getCacheMetadata(): ?array
    {
        $metadataFile = "{$this->snapshotPlan->localPath}/{$this->fileName}.meta.json";

        if (!$this->snapshotPlan->localDisk->exists($metadataFile)) {
            return null;
        }

        $contents = $this->snapshotPlan->localDisk->get($metadataFile);

        return json_decode($contents, true);
    }

    protected function setCacheMetadata(int $lastModified): void
    {
        $metadataFile = "{$this->snapshotPlan->localPath}/{$this->fileName}.meta.json";

        $this->snapshotPlan->localDisk->put(
            $metadataFile,
            json_encode([
                'fileName'     => $this->fileName,
                'lastModified' => $lastModified,
                'cachedAt'     => time(),
            ])
        );
    }

    protected function shouldRefreshCache(): bool
    {
        if (!$this->existsLocally()) {
            return true; // No local copy, must download
        }

        $metadata = $this->getCacheMetadata();
        if (!$metadata) {
            return true; // No metadata, assume stale
        }

        $archiveLastModified = $this->snapshotPlan->archiveDisk->lastModified(
            "{$this->snapshotPlan->archivePath}/{$this->fileName}"
        );

        // Refresh if archive is newer than cached version
        return $archiveLastModified > $metadata['lastModified'];
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

        // Also remove metadata file
        $metadataFile = "{$this->snapshotPlan->localPath}/{$this->fileName}.meta.json";
        if ($this->snapshotPlan->localDisk->exists($metadataFile)) {
            $this->snapshotPlan->localDisk->delete($metadataFile);
        }
    }

    public function download($useLocalCopy = false, $progressCallback = null): bool
    {
        $smartCache = config('mysql-snapshots.filesystem.cache_by_default', false);

        // Honor explicit useLocalCopy flag first
        if ($useLocalCopy && $this->existsLocally() && !$this->shouldRefreshCache()) {
            return false; // Using existing cache
        }

        // Smart cache: check if we need to refresh
        if ($smartCache && !$useLocalCopy && $this->existsLocally()) {
            if (!$this->shouldRefreshCache()) {
                return false; // Cache is fresh
            }
            // Cache is stale, remove it
            $this->removeLocalCopy();
        }

        // Download the file
        $archiveLastModified = $this->snapshotPlan->archiveDisk->lastModified(
            "{$this->snapshotPlan->archivePath}/{$this->fileName}"
        );

        // Download with or without progress tracking
        if ($progressCallback) {
            $this->downloadWithProgress($progressCallback);
        } else {
            $this->snapshotPlan->localDisk->put(
                "{$this->snapshotPlan->localPath}/{$this->fileName}",
                $this->snapshotPlan->archiveDisk->get("{$this->snapshotPlan->archivePath}/{$this->fileName}")
            );
        }

        // Store metadata
        $this->setCacheMetadata($archiveLastModified);

        return true; // Downloaded
    }

    public function load($useLocalCopy = false, $keepLocalCopy = false, $progressCallback = null): void
    {
        $this->download($useLocalCopy, $progressCallback);

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
    }

    public function remove(): bool
    {
        if ($this->existsLocally()) {
            $this->removeLocalCopy();
        }

        return $this->snapshotPlan->archiveDisk->delete("{$this->snapshotPlan->archivePath}/{$this->fileName}");
    }
}
