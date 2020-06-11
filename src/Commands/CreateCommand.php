<?php

namespace ZiffMedia\LaravelMysqlSnapshots;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class CreateCommand extends Command
{
    protected $signature = 'mysql-snapshots:create {--plan=}';
    protected $description = 'Create MySQL snapshot(s)';

    public function handle()
    {
        $plan = $this->argument('plan');

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
