<?php

namespace ZiffMedia\LaravelMysqlSnapshots\Tests;

use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use ZiffMedia\LaravelMysqlSnapshots\SnapshotPlan;

class SnapshotPlanTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        $this->cleanupFiles();

        parent::tearDown();
    }

    public function testGetAllPlansBasedOffConfig()
    {
        config()->set('mysql-snapshots.plans', [
            'daily' => [],
            'monthly' => []
        ]);

        $plans = SnapshotPlan::all();

        $this->assertCount(2, $plans);
        $this->assertCount(2, $plans->whereInstanceOf(SnapshotPlan::class));
    }

    public function testGetSettings()
    {
        $plan = new SnapshotPlan('daily', $this->defaultDailyConfig());

        $settingsFromPlan = $plan->getSettings();
        $this->assertEquals($settingsFromPlan['name'], 'daily');
        $this->assertEquals($settingsFromPlan['file_template'], 'mysql-snapshot-{plan_name}-{date|Ymd}.{extension}');
        $this->assertEquals($settingsFromPlan['mysqldump_options'], '--single-transaction');
        $this->assertEquals($settingsFromPlan['keep'], 2);
        $this->assertEquals($settingsFromPlan['environment_locks'], ['create' => 'production', 'load' => 'local']);
    }

    public function testCanCreate()
    {
        $plan = new SnapshotPlan('daily', $this->defaultDailyConfig());

        // set environment detection to return local
        app()->detectEnvironment(fn () => 'local');

        $this->assertFalse($plan->canCreate());

        // set environment detection to return production
        app()->detectEnvironment(fn () => 'production');

        $this->assertTrue($plan->canCreate());
    }

    public function testCanLoad()
    {
        $plan = new SnapshotPlan('daily', $this->defaultDailyConfig());

        // set environment detection to return local
        app()->detectEnvironment(fn () => 'local');

        $this->assertTrue($plan->canLoad());

        // set environment detection to return production
        app()->detectEnvironment(fn () => 'production');

        $this->assertFalse($plan->canLoad());
    }

    public function testLoad()
    {

    }

    public function testCreate()
    {
        $snapshotPlan = new SnapshotPlan('daily', $this->defaultDailyConfig());
        $snapshot = $snapshotPlan->create();

        $archiveDisk = Storage::disk(config('mysql-snapshots.filesystem.archive_disk'));

        $expectedFile = 'cloud-snapshots/mysql-snapshot-daily-' . date('Ymd') . '.sql.gz';

        // assert snapshot object is right
        $this->assertSame($archiveDisk, $snapshot->archiveDisk);
        $this->assertEquals($expectedFile, $snapshot->archiveFile);

        // assert file actually on disk
        $files = $archiveDisk->allFiles(config('mysql-snapshots.filesystem.archive_path'));
        $this->assertCount(1, $files);
        $this->assertFileExists(__DIR__ . '/fixtures/local-filesystem/' . $expectedFile);
    }

    protected function defaultDailyConfig()
    {
        return [
            'connection'        => 'mysql',
            'file_template'     => 'mysql-snapshot-{plan_name}-{date|Ymd}.{extension}',
            'mysqldump_options' => '--single-transaction',
            'keep'              => 2,
            'environment_locks' => [
                'create' => 'production',
                'load'   => 'local'
            ]
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
    }
}

