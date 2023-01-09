<?php

namespace ZiffMedia\LaravelMysqlSnapshots\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use ZiffMedia\LaravelMysqlSnapshots\SnapshotPlan;

class CreateCommand extends Command
{
    protected $signature = <<<'EOS'
        mysql-snapshots:create {plan? : The Plan name, will default to the first one listed under "plans"} {--cleanup}

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

        $snapshotPlans = SnapshotPlan::all();

        if (!isset($snapshotPlans[$plan])) {
            $this->error("Plan with name $plan does not appear to exist in mysql-snapshots.plans");
        }

        /** @var SnapshotPlan $snapshotPlan */
        $snapshotPlan = $snapshotPlans[$plan];

        if (!$snapshotPlan->canCreate()) {
            $this->error('Cannot created in this environment (' . app()->environment() . ')');

            return;
        }

        $snapshot = $snapshotPlan->create();

        $this->info("Snapshot successfully created at {$snapshot->fileName}");

        if ($cleanup) {
            $numberOfFiles = $snapshotPlan->cleanup();

            $this->info("Snapshot removed $numberOfFiles old snapshots.");
        }

        // $snapshotPlans = SnapshotPlan::all()->when($plan, function (Collection $snapshotPlans) use ($plan) {
        //     return $snapshotPlans->filter(function ($snapshotPlan) use ($plan) {
        //         return $snapshotPlan->name === $plan;
        //     });
        // });
        //
        // $actionableSnapshotPlans = $snapshotPlans->filter(function (SnapshotPlan $snapshotPlan) {
        //     if (!$snapshotPlan->canCreate()) {
        //         $this->warn("'{$snapshotPlan->name}' cannot create a snapshot in this environment (" . app()->environment() . ')');
        //
        //         return false;
        //     }
        //
        //     return true;
        // });
        //
        // $snapshotPlans->each(fn (SnapshotPlan $snapshotPlan) => $snapshotPlan->create());

        // $actionableSnapshotPlans->each(fn (SnapshotPlan $snapshotPlan) => $snapshotPlan->cleanup());
    }
}
