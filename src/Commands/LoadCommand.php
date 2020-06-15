<?php

namespace ZiffMedia\LaravelMysqlSnapshots\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use ZiffMedia\LaravelMysqlSnapshots\SnapshotPlan;

class LoadCommand extends Command
{
    protected $signature = 'mysql-snapshots:load {--plan=} {--file=}';
    protected $description = 'Load MySQL Snapshot(s)';

    public function handle()
    {
        $plan = $this->option('plan');

        $snapshotPlans = SnapshotPlan::all()->when($plan, function (Collection $snapshotPlans) use ($plan) {
            return $snapshotPlans->filter(function ($snapshotPlan) use ($plan) {
                return $snapshotPlan->name === $plan;
            });
        });

        $snapshotPlans->each(function (SnapshotPlan $snapshotPlan) {
            $snapshotPlan->load();
        });
    }
}
