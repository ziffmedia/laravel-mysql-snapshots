<?php

namespace ZiffMedia\LaravelMysqlSnapshots\Commands;

use Illuminate\Console\Command;
use ZiffMedia\LaravelMysqlSnapshots\Commands\Concerns\HasCommandHelpers;
use ZiffMedia\LaravelMysqlSnapshots\PlanGroup;
use ZiffMedia\LaravelMysqlSnapshots\Snapshot;
use ZiffMedia\LaravelMysqlSnapshots\SnapshotPlan;

class LoadCommand extends Command
{
    use HasCommandHelpers;

    protected $signature = <<<'EOS'
        mysql-snapshots:load
        {plan? : The Plan or Plan Group name}
        {file? : The file to use (not applicable for plan groups)}
        {--cached : Use caching}
        {--recached : Download a fresh file, even if one exists, keeping it for caching}
        {--no-drop : Don't drop all tables in database before loading snapshot}
        {--skip-post-commands : Skip post-load SQL commands}
        EOS;

    protected $description = 'Load MySQL Snapshot(s)';

    public function handle()
    {
        $plan = $this->argument('plan');

        // Check if it's a plan group
        $planGroup = PlanGroup::find($plan);

        if ($planGroup) {
            $this->info("Loading all plans in plan group: {$planGroup->name}");
            $this->newLine();

            $cached = $this->option('cached');
            $recached = $this->option('recached');
            $cacheByDefault = config('mysql-snapshots.cache_by_default', false);
            $useLocalCopy = $cached && !$recached;
            $keepLocalCopy = $cached || $recached || $cacheByDefault;
            $skipPostCommands = $this->option('skip-post-commands');

            $results = $planGroup->loadAll(
                $useLocalCopy,
                $keepLocalCopy,
                function ($message) {
                    $this->line($message);
                },
                $skipPostCommands
            );

            // Execute plan group post-load commands
            if (!$skipPostCommands) {
                $this->newLine();
                $this->info('Executing plan group post-load SQL commands...');

                $groupResults = $planGroup->executePostLoadCommands();

                if (count($groupResults) > 0) {
                    foreach ($groupResults as $result) {
                        if ($result['success']) {
                            $this->line("  <fg=green>✓</> [{$result['type']}] {$result['command']}");
                        } else {
                            $this->error("  <fg=red>✗</> [{$result['type']}] {$result['command']}");
                            $this->line("    Error: {$result['error']}");
                        }
                    }
                } else {
                    $this->line('  No plan group post-load commands configured.');
                }
            }

            $this->newLine();
            $this->info('Load Summary:');
            $this->table(
                ['Plan', 'Status', 'Details'],
                $results->map(function ($result) {
                    return [
                        $result['plan'],
                        $result['success'] ? '<fg=green>Success</>' : '<fg=red>Failed</>',
                        $result['success']
                            ? ($result['snapshot'] ?? 'N/A')
                            : ($result['reason'] ?? $result['error'] ?? 'Unknown'),
                    ];
                })
            );

            $this->warnAboutUnacceptedFiles();

            return;
        }

        $snapshotPlans = SnapshotPlan::all();

        /** @var SnapshotPlan $snapshotPlan */
        $snapshotPlan = (!$plan)
            ? $snapshotPlans->first()
            : $snapshotPlans->firstWhere('name', $plan);

        if (!$snapshotPlan) {
            $this->error('Could not find a suitable plan to load from.');

            return;
        }

        if (!$snapshotPlan->canLoad()) {
            $this->error('Cannot load in this environment (' . app()->environment() . ')');

            return;
        }

        $file = $this->argument('file') ?? 1;

        /** @var Snapshot $snapshot */
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
        $cacheByDefault = config('mysql-snapshots.cache_by_default', false);

        $useLocalCopy = $cached && !$recached;
        $keepLocalCopy = $cached || $recached || $cacheByDefault;

        $noDrop = $this->option('no-drop');

        if (!$noDrop) {
            $this->info('Dropping existing tables');

            $snapshotPlan->dropLocalTables();
        }

        // Setup progress bar if downloading
        $progressBar = null;
        $progressCallback = null;

        if (!$useLocalCopy || !$snapshot->existsLocally()) {
            $progressCallback = function ($downloaded, $total) use (&$progressBar) {
                if (!$progressBar) {
                    $progressBar = $this->output->createProgressBar($total);
                    $progressBar->setFormat('very_verbose');
                }
                $progressBar->setProgress($downloaded);
            };
        }

        $cacheInfo = $snapshot->load($useLocalCopy, $keepLocalCopy, $progressCallback);

        if ($progressBar) {
            $progressBar->finish();
            $this->newLine();
        }

        // Provide cache feedback
        if ($cacheInfo['used_cache']) {
            if ($cacheInfo['smart_cache_enabled']) {
                $this->info('Using cached snapshot (validated by smart cache)');
            } else {
                $this->info('Using cached snapshot');
            }
        } elseif ($cacheInfo['cache_was_stale']) {
            $this->info('Downloaded fresh snapshot (cached copy was stale)');
        }

        if ($keepLocalCopy) {
            $this->info("Keeping {$snapshot->fileName} for future loads");
        }

        // Execute post-load commands
        if (!$this->option('skip-post-commands')) {
            $this->newLine();
            $this->info('Executing post-load SQL commands...');

            $results = $snapshotPlan->executePostLoadCommands();

            if (count($results) > 0) {
                foreach ($results as $result) {
                    if ($result['success']) {
                        $this->line("  <fg=green>✓</> [{$result['type']}] {$result['command']}");
                    } else {
                        $this->error("  <fg=red>✗</> [{$result['type']}] {$result['command']}");
                        $this->line("    Error: {$result['error']}");
                    }
                }
            } else {
                $this->line('  No post-load commands configured.');
            }

            $this->newLine();
        }

        $clearedFiles = $snapshotPlan->clearCached($keepLocalCopy ? $snapshot->fileName : null);

        if ($clearedFiles) {
            $this->info('Files cleared:');

            foreach ($clearedFiles as $clearedFile) {
                $this->line("  $clearedFile");
            }
        }

        $this->warnAboutUnacceptedFiles();
    }
}
