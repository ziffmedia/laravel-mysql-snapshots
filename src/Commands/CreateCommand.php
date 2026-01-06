<?php

namespace ZiffMedia\LaravelMysqlSnapshots\Commands;

use Illuminate\Console\Command;
use ZiffMedia\LaravelMysqlSnapshots\PlanGroup;
use ZiffMedia\LaravelMysqlSnapshots\SnapshotPlan;

class CreateCommand extends Command
{
    protected $signature = <<<'EOS'
        mysql-snapshots:create
        {plan? : The Plan or Plan Group name}
        {--cleanup : Cleanup old snapshots after creation}
        EOS;

    protected $description = 'Create MySQL snapshot(s)';

    public function handle()
    {
        $plan = $this->argument('plan');
        $cleanup = $this->option('cleanup', false);

        if (!$plan) {
            $plans = config('mysql-snapshots.plans');
            $plan = key($plans);
        }

        // Check if it's a plan group (only if plan exists)
        $planGroup = $plan ? PlanGroup::find($plan) : null;

        if ($planGroup) {
            // Create all plans in plan group
            $this->info("Creating snapshots for plan group: {$planGroup->name}");
            $this->newLine();

            if ($this->getOutput()->isVerbose()) {
                $planGroup->displayMessagesUsing(fn ($message) => $this->line($message));
            }

            $snapshots = $planGroup->createAll();

            $this->newLine();
            $this->info("Created {$snapshots->count()} snapshot(s)");

            if ($cleanup) {
                foreach ($planGroup->plans as $snapshotPlan) {
                    $numberOfFiles = $snapshotPlan->cleanup();
                    if ($numberOfFiles > 0) {
                        $this->info("Removed {$numberOfFiles} old snapshot(s) from {$snapshotPlan->name}");
                    }
                }
            }

            return;
        }

        // Original single plan logic
        $snapshotPlans = SnapshotPlan::all();

        if (!isset($snapshotPlans[$plan])) {
            $this->error("Plan with name $plan does not appear to exist in mysql-snapshots.plans");

            return;
        }

        /** @var SnapshotPlan $snapshotPlan */
        $snapshotPlan = $snapshotPlans[$plan];

        if (!$snapshotPlan->canCreate()) {
            $this->error('Cannot create in this environment (' . app()->environment() . ')');

            return;
        }

        if ($this->getOutput()->isVerbose()) {
            $snapshotPlan->displayMessagesUsing(fn ($message) => $this->line($message));
        }

        $snapshot = $snapshotPlan->create();

        $this->info("Snapshot successfully created at {$snapshot->fileName}");

        if ($cleanup) {
            $numberOfFiles = $snapshotPlan->cleanup();
            $this->info("Snapshot removed $numberOfFiles old snapshots.");
        }
    }
}
