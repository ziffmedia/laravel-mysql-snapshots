<?php

namespace ZiffMedia\LaravelMysqlSnapshots\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use ZiffMedia\LaravelMysqlSnapshots\SnapshotPlan;

class ListCommand extends Command
{
    protected $signature = 'mysql-snapshots:list {--plan=}';
    protected $description = 'List MySQL Snapshot(s)';

    public function handle()
    {
        $plan = $this->option('plan');

        $snapshotPlans = SnapshotPlan::all()->when($plan, function (Collection $snapshotPlans) use ($plan) {
            return $snapshotPlans->filter(function ($snapshotPlan) use ($plan) {
                return $snapshotPlan->name === $plan;
            });
        });

        $snapshotPlans->each(function (SnapshotPlan $snapshotPlan) {
            $this->info('Plan "' . $snapshotPlan->name . '"');

            $snapshotPlan->list()
                ->each(function ($file, $i) {
                    $this->line(' - ' . $file);
                });

            $this->line('');
        });
    }
}
