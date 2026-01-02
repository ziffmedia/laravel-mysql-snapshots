<?php

namespace ZiffMedia\LaravelMysqlSnapshots\Tests;

use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use ZiffMedia\LaravelMysqlSnapshots\PlanGroup;
use ZiffMedia\LaravelMysqlSnapshots\Snapshot;
use ZiffMedia\LaravelMysqlSnapshots\SnapshotPlan;

class OutputCallbacksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.local.root', __DIR__ . '/fixtures/local-filesystem/');

        config()->set('mysql-snapshots', include __DIR__ . '/../config/mysql-snapshots.php');

        config()->set('mysql-snapshots.filesystem.archive_disk', 'local');
        config()->set('mysql-snapshots.filesystem.archive_path', 'cloud-snapshots');
        config()->set('mysql-snapshots.utilities.mysqldump', __DIR__ . '/fixtures/fakemysqldump');

        $this->cleanupFiles();

        SnapshotPlan::$unacceptedFiles = [];
    }

    protected function tearDown(): void
    {
        $this->cleanupFiles();

        parent::tearDown();
    }

    public function test_snapshot_plan_can_set_messaging_callback()
    {
        $snapshotPlan = new SnapshotPlan('daily', $this->defaultDailyConfig());

        $messages = [];
        $snapshotPlan->displayMessagesUsing(function ($message) use (&$messages) {
            $messages[] = $message;
        });

        // Create a snapshot which should trigger messaging callbacks
        $snapshotPlan->create();

        // Verify that messages were captured
        $this->assertNotEmpty($messages);
        $this->assertGreaterThan(0, count($messages));

        // Verify that mysqldump command message was captured
        $this->assertTrue(
            collect($messages)->contains(fn ($msg) => str_contains($msg, 'Running:')),
            'Should have captured mysqldump command message'
        );
    }

    public function test_snapshot_can_set_progress_callback()
    {
        // First create a snapshot to have something to download
        $snapshotPlan = new SnapshotPlan('daily', $this->defaultDailyConfig());
        $snapshot = $snapshotPlan->create();

        // Now test downloading with progress callback
        $progressCalls = [];
        $snapshot->displayProgressUsing(function ($current, $total) use (&$progressCalls) {
            $progressCalls[] = ['current' => $current, 'total' => $total];
        });

        $snapshot->download(false);

        // Verify that progress callback was called
        $this->assertNotEmpty($progressCalls);
        $this->assertGreaterThan(0, count($progressCalls));

        // Verify the last call has current == total (completed)
        $lastCall = end($progressCalls);
        $this->assertEquals($lastCall['current'], $lastCall['total']);
    }

    public function test_snapshot_plan_has_messaging_callback_for_drop_tables()
    {
        // This test verifies the callback mechanism is in place
        // We don't actually call dropLocalTables() as it requires a real database connection
        $snapshotPlan = new SnapshotPlan('daily', $this->defaultDailyConfig());

        $messages = [];
        $snapshotPlan->displayMessagesUsing(function ($message) use (&$messages) {
            $messages[] = $message;
        });

        // Verify the callback is set up correctly by checking the property
        $reflection = new \ReflectionClass($snapshotPlan);
        $property = $reflection->getProperty('messagingCallback');
        $property->setAccessible(true);

        $this->assertNotNull($property->getValue($snapshotPlan));
    }

    public function test_snapshot_plan_execute_post_load_commands_triggers_messaging()
    {
        $config = $this->defaultDailyConfig();
        $config['post_load_sqls'] = [
            'SET FOREIGN_KEY_CHECKS=0',
            'TRUNCATE TABLE sessions',
        ];

        $snapshotPlan = new SnapshotPlan('daily', $config);

        $messages = [];
        $snapshotPlan->displayMessagesUsing(function ($message) use (&$messages) {
            $messages[] = $message;
        });

        $snapshotPlan->executePostLoadCommands();

        // Verify that SQL command messages were captured
        $this->assertNotEmpty($messages);
        $this->assertGreaterThanOrEqual(2, count($messages));

        $sqlMessages = collect($messages)->filter(fn ($msg) => str_contains($msg, 'Running SQL:'));
        $this->assertGreaterThanOrEqual(2, $sqlMessages->count());
    }

    public function test_plan_group_can_set_messaging_callback()
    {
        // Create some test snapshots first
        $plan1 = new SnapshotPlan('daily', $this->defaultDailyConfig());
        $plan1->create();

        config()->set('mysql-snapshots.plans', [
            'daily' => $this->defaultDailyConfig(),
        ]);

        config()->set('mysql-snapshots.plan_groups', [
            'all' => [
                'plans' => ['daily'],
            ],
        ]);

        $planGroup = PlanGroup::find('all');

        $messages = [];
        $planGroup->displayMessagesUsing(function ($message) use (&$messages) {
            $messages[] = $message;
        });

        // Test createAll
        $planGroup->createAll();

        $this->assertNotEmpty($messages);
        $this->assertTrue(
            collect($messages)->contains(fn ($msg) => str_contains($msg, 'Creating snapshot for plan:')),
            'Should have captured plan creation message'
        );
    }

    public function test_plan_group_load_all_triggers_messaging()
    {
        // Create some test snapshots first
        $plan1 = new SnapshotPlan('daily', $this->defaultDailyConfig());
        $plan1->create();

        config()->set('mysql-snapshots.plans', [
            'daily' => $this->defaultDailyConfig(),
        ]);

        config()->set('mysql-snapshots.plan_groups', [
            'all' => [
                'plans' => ['daily'],
            ],
        ]);

        $planGroup = PlanGroup::find('all');

        $messages = [];
        $planGroup->displayMessagesUsing(function ($message) use (&$messages) {
            $messages[] = $message;
        });

        // Set environment for loading
        app()->detectEnvironment(fn () => 'local');

        $planGroup->loadAll(false, false, true); // skipPostCommands = true to avoid SQL execution

        $this->assertNotEmpty($messages);
        $this->assertTrue(
            collect($messages)->contains(fn ($msg) => str_contains($msg, 'Loading plan:')),
            'Should have captured plan loading message'
        );
    }

    public function test_plan_group_has_messaging_callback_for_drop_tables()
    {
        // This test verifies the callback mechanism is in place
        // We don't actually call dropTables() as it requires a real database connection
        config()->set('mysql-snapshots.plans', [
            'daily' => $this->defaultDailyConfig(),
        ]);

        config()->set('mysql-snapshots.plan_groups', [
            'all' => [
                'plans' => ['daily'],
            ],
        ]);

        $planGroup = PlanGroup::find('all');

        $messages = [];
        $planGroup->displayMessagesUsing(function ($message) use (&$messages) {
            $messages[] = $message;
        });

        // Verify the callback is set up correctly by checking the property
        $reflection = new \ReflectionClass($planGroup);
        $property = $reflection->getProperty('messagingCallback');
        $property->setAccessible(true);

        $this->assertNotNull($property->getValue($planGroup));
    }

    public function test_plan_group_execute_post_load_commands_triggers_messaging()
    {
        config()->set('mysql-snapshots.plans', [
            'daily' => $this->defaultDailyConfig(),
        ]);

        config()->set('mysql-snapshots.plan_groups', [
            'all' => [
                'plans'          => ['daily'],
                'post_load_sqls' => [
                    'SET FOREIGN_KEY_CHECKS=0',
                ],
            ],
        ]);

        $planGroup = PlanGroup::find('all');

        $messages = [];
        $planGroup->displayMessagesUsing(function ($message) use (&$messages) {
            $messages[] = $message;
        });

        $planGroup->executePostLoadCommands();

        $this->assertNotEmpty($messages);
        $this->assertTrue(
            collect($messages)->contains(fn ($msg) => str_contains($msg, 'Running SQL:')),
            'Should have captured SQL execution message'
        );
    }

    public function test_callbacks_are_optional()
    {
        // Test that operations work without callbacks set
        $snapshotPlan = new SnapshotPlan('daily', $this->defaultDailyConfig());

        // Should not throw exception when no callback is set
        $snapshot = $snapshotPlan->create();
        $this->assertNotNull($snapshot);

        // Download without progress callback should work
        $snapshot->download(false);
        $this->assertTrue($snapshot->existsLocally());
    }

    public function test_messaging_callback_propagates_to_child_plans()
    {
        config()->set('mysql-snapshots.plans', [
            'daily'   => $this->defaultDailyConfig(),
            'monthly' => $this->defaultDailyConfig(),
        ]);

        config()->set('mysql-snapshots.plan_groups', [
            'all' => [
                'plans' => ['daily', 'monthly'],
            ],
        ]);

        $planGroup = PlanGroup::find('all');

        $messages = [];
        $planGroup->displayMessagesUsing(function ($message) use (&$messages) {
            $messages[] = $message;
        });

        $planGroup->createAll();

        // Should have messages from both plans
        $this->assertTrue(
            collect($messages)->contains(fn ($msg) => str_contains($msg, 'plan: daily')),
            'Should have messages from daily plan'
        );

        $this->assertTrue(
            collect($messages)->contains(fn ($msg) => str_contains($msg, 'plan: monthly')),
            'Should have messages from monthly plan'
        );
    }

    protected function defaultDailyConfig(): array
    {
        return [
            'connection'        => 'mysql',
            'file_template'     => 'mysql-snapshot-daily-{date|Ymd}',
            'mysqldump_options' => '--single-transaction',
            'keep_last'         => 2,
            'environment_locks' => [
                'create' => 'production',
                'load'   => 'local',
            ],
        ];
    }

    protected function cleanupFiles()
    {
        $archiveDisk = Storage::disk(config('mysql-snapshots.filesystem.archive_disk'));

        foreach ($archiveDisk->allFiles(config('mysql-snapshots.filesystem.archive_path')) as $file) {
            $archiveDisk->delete($file);
        }

        $localDisk = Storage::disk(config('mysql-snapshots.filesystem.local_disk'));

        foreach ($localDisk->allFiles(config('mysql-snapshots.filesystem.local_path')) as $file) {
            $localDisk->delete($file);
        }

        $localDisk->delete('fakemysqldump-arguments.txt');
    }
}
