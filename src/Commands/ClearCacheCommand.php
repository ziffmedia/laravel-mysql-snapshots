<?php

namespace ZiffMedia\LaravelMysqlSnapshots\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ClearCacheCommand extends Command
{
    protected $signature = 'mysql-snapshots:clear-cache {--except-file=}';

    protected $description = 'Clear cache of snapshots';

    public function handle()
    {
        $exceptFile = $this->option('except-file');

        // check for cached files
        $localDisk = Storage::disk(config('mysql-snapshots.filesystem.local_disk'));
        $localPath = config('mysql-snapshots.filesystem.local_path');

        $files = $localDisk->allFiles($localPath);

        foreach ($files as $file) {
            if (!Str::startsWith($file, $localPath)) {
                continue;
            }

            $fileName = Str::substr($file, strlen($localPath) + 1);

            if ($exceptFile && $exceptFile == $fileName) {
                continue;
            }

            $this->info("Deleting {$file}");

            $localDisk->delete($file);
        }
    }
}
