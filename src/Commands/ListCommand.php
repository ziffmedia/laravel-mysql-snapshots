<?php

namespace ZiffMedia\LaravelMysqlSnapshots\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZiffMedia\LaravelMysqlSnapshots\Snapshot;
use ZiffMedia\LaravelMysqlSnapshots\SnapshotPlan;

class ListCommand extends Command
{
    protected $signature = <<<'EOS'
        mysql-snapshots:list
        {plan? : The Plan name, will default to the first one listed under "plans"}
        EOS;

    protected $description = 'List MySQL Snapshot(s)';

    public function handle()
    {
        $plan = $this->argument('plan');

        $snapshotPlans = SnapshotPlan::all()->when($plan, function (Collection $snapshotPlans) use ($plan) {
            return $snapshotPlans->filter(function ($snapshotPlan) use ($plan) {
                return $snapshotPlan->name === $plan;
            });
        });

        $this->newLine();

        $snapshotPlans->each(function (SnapshotPlan $snapshotPlan) {
            $this->info('Plan "' . $snapshotPlan->name . '"');

            $snapshots = $snapshotPlan->snapshots;

            if ($snapshots->count() === 0) {
                $this->line('  None yet.');

                return;
            }

            $snapshots->each(function (Snapshot $snapshot, int $index) {
                $fileNum = $index + 1;
                $this->line("  <fg=yellow>{$fileNum}.</> {$snapshot->fileName}");
            });

            $this->line('');
        });

        // check for cached files
        $localDisk = Storage::disk(config('mysql-snapshots.filesystem.local_disk'));
        $localPath = config('mysql-snapshots.filesystem.local_path');

        $files = $localDisk->allFiles($localPath);

        if ($files) {
            $this->line('<fg=yellow>Locally cached files:</>');

            foreach ($files as $file) {
                if (!Str::startsWith($file, $localPath)) {
                    continue;
                }

                $fileName = Str::substr($file, strlen($localPath) + 1);

                $this->line("  -- {$fileName}");
            }
        }

        // Warn about unaccepted files
        if (count(SnapshotPlan::$unacceptedFiles) > 0) {
            $this->newLine();
            $this->warn('Warning: Found ' . count(SnapshotPlan::$unacceptedFiles) . ' file(s) in the archive that do not match any configured plan:');

            foreach (SnapshotPlan::$unacceptedFiles as $unacceptedFile) {
                $this->line("  {$unacceptedFile}");
            }

            $this->line('');
            $this->line('These files may be from removed plans and can be safely deleted if no longer needed.');
        }

        $this->newLine();
    }
}
