<?php

namespace ZiffMedia\LaravelMysqlSnapshots\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZiffMedia\LaravelMysqlSnapshots\Snapshot;
use ZiffMedia\LaravelMysqlSnapshots\SnapshotPlan;

class LoadCommand extends Command
{
    protected $signature = <<<EOS
        mysql-snapshots:load
        {plan? : The Plan name, will default to the first one listed under "plans"}
        {file? : The file to use, will default to the latest file in the plan}
        {--cached : Use caching}
        {--recached : Download a fresh file, even if one exists, keeping it for caching}
        EOS;

    protected $description = 'Load MySQL Snapshot(s)';

    public function handle()
    {
        $plan = $this->argument('plan');

        $snapshotPlans = SnapshotPlan::all();

        /** @var SnapshotPlan $snapshotPlan */
        $snapshotPlan = (!$plan)
            ? $snapshotPlans->first()
            : $snapshotPlans->firstWhere('name', $plan);

        if (!$snapshotPlan) {
            $this->error('Could not find a suitable plan to load from.');

            return;
        }

        $file = $this->argument('file') ?? 1;

        $snapshot = is_numeric($file)
            ? ($snapshotPlan->snapshots[$file - 1] ?? null)
            : $snapshotPlan->snapshots->firstWhere('fileName', $file);

        if (!$snapshot) {
            $this->error(
                is_numeric($file)
                    ? "Snapshot at index $file does not exist"
                    : "Snapshot with file name $file does not exist"
            );

            return;
        }

        $this->info("Loading {$snapshot->fileName}...");

        $cached = $this->option('cached');
        $recached = $this->option('recached');

        $useLocalCopy = $cached && !$recached;
        $keepLocalCopy = $cached || $recached;

        if ($useLocalCopy && $snapshot->existsLocally()) {
            $this->info("Using cached file {$snapshot->fileName}");
        }

        $snapshot->load($useLocalCopy, $keepLocalCopy);

        if ($keepLocalCopy) {
            $this->info("Keeping {$snapshot->fileName} for future loads");
        }

        $clearedFiles = $snapshotPlan->clearCached($keepLocalCopy ? $snapshot->fileName : null);

        if ($clearedFiles) {
            $this->info('Files cleared:');

            foreach ($clearedFiles as $clearedFile) {
                $this->line("  $clearedFile");
            }
        }
    }
}
