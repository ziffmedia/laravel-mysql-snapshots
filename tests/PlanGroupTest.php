<?php

namespace ZiffMedia\LaravelMysqlSnapshots\Tests;

use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use ZiffMedia\LaravelMysqlSnapshots\PlanGroup;
use ZiffMedia\LaravelMysqlSnapshots\SnapshotPlan;

class PlanGroupTest extends TestCase
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

        // Reset static unacceptedFiles array
        SnapshotPlan::$unacceptedFiles = [];
    }

    protected function tearDown(): void
    {
        $this->cleanupFiles();

        parent::tearDown();
    }

    public function test_find_throws_exception_when_name_is_empty()
    {
        // Configure a plan group
        config()->set('mysql-snapshots.plan_groups', [
            'daily-group' => [
                'plans' => ['daily'],
            ],
        ]);

        config()->set('mysql-snapshots.plans', [
            'daily' => $this->defaultDailyConfig(),
        ]);

        // Test that find throws exception when passed empty string
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Plan group name cannot be empty');

        PlanGroup::find('');
    }

    public function test_find_returns_null_when_plan_group_does_not_exist()
    {
        // Configure a plan group
        config()->set('mysql-snapshots.plan_groups', [
            'daily-group' => [
                'plans' => ['daily'],
            ],
        ]);

        config()->set('mysql-snapshots.plans', [
            'daily' => $this->defaultDailyConfig(),
        ]);

        // Test that find returns null for non-existent group
        $planGroup = PlanGroup::find('non-existent-group');

        $this->assertNull($planGroup);
    }

    public function test_find_returns_plan_group_when_it_exists()
    {
        // Configure a plan group
        config()->set('mysql-snapshots.plan_groups', [
            'daily-group' => [
                'plans'          => ['daily'],
                'post_load_sqls' => ['SELECT 1'],
            ],
        ]);

        config()->set('mysql-snapshots.plans', [
            'daily' => $this->defaultDailyConfig(),
        ]);

        // Test that find returns the plan group
        $planGroup = PlanGroup::find('daily-group');

        $this->assertInstanceOf(PlanGroup::class, $planGroup);
        $this->assertEquals('daily-group', $planGroup->name);
        $this->assertEquals(['daily'], $planGroup->planNames);
        $this->assertEquals(['SELECT 1'], $planGroup->postLoadSqls);
    }

    public function test_all_returns_empty_collection_when_no_plan_groups_configured()
    {
        // Ensure no plan groups are configured
        config()->set('mysql-snapshots.plan_groups', []);

        $planGroups = PlanGroup::all();

        $this->assertCount(0, $planGroups);
    }

    public function test_all_returns_all_plan_groups()
    {
        // Configure multiple plan groups
        config()->set('mysql-snapshots.plan_groups', [
            'group-1' => [
                'plans' => ['daily'],
            ],
            'group-2' => [
                'plans' => ['daily'],
            ],
        ]);

        config()->set('mysql-snapshots.plans', [
            'daily' => $this->defaultDailyConfig(),
        ]);

        $planGroups = PlanGroup::all();

        $this->assertCount(2, $planGroups);
        $this->assertInstanceOf(PlanGroup::class, $planGroups->first());
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
