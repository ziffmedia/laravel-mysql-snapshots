<?php

namespace ZiffMedia\LaravelMysqlSnapshots\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use ZiffMedia\LaravelMysqlSnapshots\SnapshotPlan;

class CreateCommand extends Command
{
    protected $signature = 'mysql-snapshots:create {--plan=}';
    protected $description = 'Create MySQL snapshot(s)';

    public function handle()
    {
        $plan = $this->option('plan');

        $snapshotPlans = SnapshotPlan::all()->when($plan, function (Collection $snapshotPlans) use ($plan) {
            return $snapshotPlans->filter(function ($snapshotPlan) use ($plan) {
                return $snapshotPlan->name === $plan;
            });
        });

        $snapshotPlans
            ->each(function (SnapshotPlan $snapshotPlan) {
                if (!$snapshotPlan->canCreate()) {
                    return;
                }

                $snapshotPlan->create();
            })
            ->each(function (SnapshotPlan $snapshotPlan) {
                if (!$snapshotPlan->canCreate()) {
                    return;
                }

                $snapshotPlan->cleanup();
            });
    }
}
