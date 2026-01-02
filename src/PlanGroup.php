<?php

namespace ZiffMedia\LaravelMysqlSnapshots;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use ZiffMedia\LaravelMysqlSnapshots\Commands\Concerns\HasOutputCallbacks;

class PlanGroup
{
    use HasOutputCallbacks;

    public string $name;

    /** @var array<string> */
    public array $planNames;

    public array $postLoadSqls = [];

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
     *
     * @throws InvalidArgumentException if name is empty
     */
    public static function find(string $name): ?PlanGroup
    {
        if (empty($name)) {
            throw new InvalidArgumentException('Plan group name cannot be empty');
        }

        return static::all()->firstWhere('name', $name);
    }

    public function __construct(string $name, array $config)
    {
        $this->name = $name;
        $this->planNames = $config['plans'] ?? [];
        $this->postLoadSqls = $config['post_load_sqls'] ?? [];

        if (empty($this->planNames)) {
            throw new InvalidArgumentException("Plan group '{$name}' must contain at least one plan");
        }

        // Load all referenced plans
        $allPlans = SnapshotPlan::all();
        $this->plans = collect($this->planNames)->map(function ($planName) use ($allPlans, $name) {
            $plan = $allPlans->firstWhere('name', $planName);

            if (!$plan) {
                throw new RuntimeException("Plan group '{$name}' references non-existent plan '{$planName}'");
            }

            return $plan;
        });
    }

    /**
     * Create snapshots for all plans in this plan group
     *
     * @return Collection<Snapshot>
     */
    public function createAll(): Collection
    {
        return $this->plans->map(function (SnapshotPlan $plan) {
            $this->callMessaging("Creating snapshot for plan: {$plan->name}");

            if (!$plan->canCreate()) {
                $this->callMessaging('  Skipped (environment lock)');

                return null;
            }

            // Pass messaging callback to the plan
            $plan->displayMessagesUsing($this->messagingCallback ?? fn () => null);

            $snapshot = $plan->create();
            $this->callMessaging("  Created: {$snapshot->fileName}");

            return $snapshot;
        })->filter(); // Remove nulls (skipped plans)
    }

    /**
     * Load all plans in this plan group sequentially
     */
    public function loadAll(
        bool $useLocalCopy = false,
        bool $keepLocalCopy = false,
        bool $skipPostCommands = false
    ): Collection {
        $results = collect();

        foreach ($this->plans as $plan) {
            $this->callMessaging("Loading plan: {$plan->name}");

            if (!$plan->canLoad()) {
                $this->callMessaging('  Skipped (environment lock)');

                $results->push([
                    'plan'    => $plan->name,
                    'success' => false,
                    'reason'  => 'environment_lock',
                ]);

                continue;
            }

            $snapshot = $plan->snapshots->first();

            if (!$snapshot) {
                $this->callMessaging('  Skipped (no snapshots available)');

                $results->push([
                    'plan'    => $plan->name,
                    'success' => false,
                    'reason'  => 'no_snapshots',
                ]);

                continue;
            }

            try {
                // Pass messaging callback to the plan and snapshot
                $plan->displayMessagesUsing($this->messagingCallback ?? fn () => null);
                $snapshot->displayMessagesUsing($this->messagingCallback ?? fn () => null);
                $snapshot->displayProgressUsing($this->progressCallback ?? fn () => null);

                $snapshot->load($useLocalCopy, $keepLocalCopy);

                if (!$skipPostCommands) {
                    $plan->executePostLoadCommands();
                }

                $this->callMessaging("  Loaded: {$snapshot->fileName}");

                $results->push([
                    'plan'     => $plan->name,
                    'success'  => true,
                    'snapshot' => $snapshot->fileName,
                ]);
            } catch (Exception $e) {
                $this->callMessaging("  Failed: {$e->getMessage()}");

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

    /**
     * Execute post-load SQL commands for this plan group
     * Note: Uses the connection from the first plan in the group
     */
    public function executePostLoadCommands(): array
    {
        if (empty($this->postLoadSqls)) {
            return [];
        }

        $results = [];

        // Use the connection from the first plan in the group
        $connection = $this->plans->first()->connection;

        foreach ($this->postLoadSqls as $command) {
            try {
                $this->callMessaging('Running SQL: ' . $command);

                DB::connection($connection)->statement($command);

                $results[] = [
                    'command' => $command,
                    'type'    => 'plan_group',
                    'success' => true,
                ];
            } catch (Exception $e) {
                $results[] = [
                    'command' => $command,
                    'type'    => 'plan_group',
                    'success' => false,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Drop tables on all unique database connections used by plans in this group
     */
    public function dropTables(): void
    {
        // Collect unique connections from all plans
        $uniqueConnections = $this->plans
            ->map(fn (SnapshotPlan $plan) => $plan->connection)
            ->unique()
            ->values();

        // Drop tables once per unique connection
        foreach ($uniqueConnections as $connection) {
            $this->callMessaging('Dropping tables on connection: ' . $connection);

            DB::connection($connection)
                ->getSchemaBuilder()
                ->dropAllTables();
        }
    }
}
