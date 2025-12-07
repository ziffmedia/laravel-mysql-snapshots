<?php

namespace ZiffMedia\LaravelMysqlSnapshots;

use Illuminate\Support\Collection;

class PlanGroup
{
    public string $name;

    /** @var array<string> */
    public array $planNames;

    /** @var Collection<SnapshotPlan> */
    public readonly Collection $plans;

    /**
     * Get all plan groups from config
     *
     * @return Collection<PlanGroup>
     */
    public static function all(): Collection
    {
        $planGroupConfigs = config('mysql-snapshots.plan_groups', []);

        if (empty($planGroupConfigs)) {
            return collect();
        }

        return collect($planGroupConfigs)
            ->map(fn ($config, $name) => new PlanGroup($name, $config));
    }

    /**
     * Find a plan group by name
     */
    public static function find(string $name): ?PlanGroup
    {
        return static::all()->firstWhere('name', $name);
    }

    public function __construct(string $name, array $config)
    {
        $this->name = $name;
        $this->planNames = $config['plans'] ?? [];

        if (empty($this->planNames)) {
            throw new \InvalidArgumentException("Plan group '{$name}' must contain at least one plan");
        }

        // Load all referenced plans
        $allPlans = SnapshotPlan::all();
        $this->plans = collect($this->planNames)->map(function ($planName) use ($allPlans, $name) {
            $plan = $allPlans->firstWhere('name', $planName);

            if (!$plan) {
                throw new \RuntimeException("Plan group '{$name}' references non-existent plan '{$planName}'");
            }

            return $plan;
        });
    }

    /**
     * Create snapshots for all plans in this plan group
     *
     * @return Collection<Snapshot>
     */
    public function createAll(?callable $progressCallback = null): Collection
    {
        $progressCallback = $progressCallback ?? fn () => null;

        return $this->plans->map(function (SnapshotPlan $plan) use ($progressCallback) {
            $progressCallback("Creating snapshot for plan: {$plan->name}");

            if (!$plan->canCreate()) {
                $progressCallback('  Skipped (environment lock)');

                return null;
            }

            $snapshot = $plan->create($progressCallback);
            $progressCallback("  Created: {$snapshot->fileName}");

            return $snapshot;
        })->filter(); // Remove nulls (skipped plans)
    }

    /**
     * Load all plans in this plan group sequentially
     */
    public function loadAll(
        bool $useLocalCopy = false,
        bool $keepLocalCopy = false,
        ?callable $progressCallback = null,
        bool $skipPostCommands = false
    ): Collection {
        $progressCallback = $progressCallback ?? fn () => null;
        $results = collect();

        foreach ($this->plans as $plan) {
            $progressCallback("Loading plan: {$plan->name}");

            if (!$plan->canLoad()) {
                $progressCallback('  Skipped (environment lock)');
                $results->push([
                    'plan'    => $plan->name,
                    'success' => false,
                    'reason'  => 'environment_lock',
                ]);

                continue;
            }

            $snapshot = $plan->snapshots->first();

            if (!$snapshot) {
                $progressCallback('  Skipped (no snapshots available)');
                $results->push([
                    'plan'    => $plan->name,
                    'success' => false,
                    'reason'  => 'no_snapshots',
                ]);

                continue;
            }

            try {
                $snapshot->load($useLocalCopy, $keepLocalCopy);

                if (!$skipPostCommands) {
                    $plan->executePostLoadCommands();
                }

                $progressCallback("  Loaded: {$snapshot->fileName}");
                $results->push([
                    'plan'     => $plan->name,
                    'success'  => true,
                    'snapshot' => $snapshot->fileName,
                ]);
            } catch (\Exception $e) {
                $progressCallback("  Failed: {$e->getMessage()}");
                $results->push([
                    'plan'    => $plan->name,
                    'success' => false,
                    'reason'  => 'exception',
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }
}
