<?php

namespace ZiffMedia\LaravelMysqlSnapshots\Tests;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
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
            'daily'   => [],
            'monthly' => [],
        ]);

        $plans = SnapshotPlan::all();

        $this->assertCount(2, $plans);
        $this->assertCount(2, $plans->whereInstanceOf(SnapshotPlan::class));
    }

    public function testGetSettings()
    {
        $plan = new SnapshotPlan('daily', $this->defaultDailyConfig());

        $settingsFromPlan = $plan->getSettings();
        $this->assertEquals('daily', $settingsFromPlan['name']);
        $this->assertEquals('mysql-snapshot-daily-{date|Ymd}', $settingsFromPlan['file_template']);
        $this->assertEquals('--single-transaction', $settingsFromPlan['mysqldump_options']);
        $this->assertEquals(2, $settingsFromPlan['keep_last']);
        $this->assertEquals(['create' => 'production', 'load' => 'local'], $settingsFromPlan['environment_locks']);
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

    public function testCreate()
    {
        $snapshotPlan = new SnapshotPlan('daily', $this->defaultDailyConfig());
        $snapshot = $snapshotPlan->create();

        $archiveDisk = Storage::disk(config('mysql-snapshots.filesystem.archive_disk'));

        $expectedFile = 'cloud-snapshots/mysql-snapshot-daily-' . date('Ymd') . '.sql.gz';

        // assert snapshot object is right
        $this->assertEquals('mysql-snapshot-daily-' . date('Ymd') . '.sql.gz', $snapshot->fileName);

        // assert file actually on disk
        $files = $archiveDisk->allFiles(config('mysql-snapshots.filesystem.archive_path'));
        $this->assertCount(1, $files);
        $this->assertFileExists(__DIR__ . '/fixtures/local-filesystem/' . $expectedFile);
    }

    public function testCreateWithTableList()
    {
        $config = $this->defaultDailyConfig();
        $config['tables'] = ['foo', 'bar', 'bam'];

        $snapshotPlan = new SnapshotPlan('daily', $config);
        $snapshot = $snapshotPlan->create();

        // assert command
        $this->assertStringContainsString('forge foo bar bam', file_get_contents(__DIR__ . '/fixtures/local-filesystem/fakemysqldump-arguments.txt'));
    }

    public function testCreateWithTableListAndSchemaOnly()
    {
        $config = $this->defaultDailyConfig();
        $config['tables'] = ['foo', 'bar', 'bam'];
        $config['schema_only_tables'] = ['bar'];

        $snapshotPlan = new SnapshotPlan('daily', $config);
        $snapshot = $snapshotPlan->create();

        // assert command
        $this->assertStringContainsString('forge foo bam', file_get_contents(__DIR__ . '/fixtures/local-filesystem/fakemysqldump-arguments.txt'));
        $this->assertStringContainsString('forge bar', file_get_contents(__DIR__ . '/fixtures/local-filesystem/fakemysqldump-arguments.txt'));
    }

    public function testSnapshotPlanWillThrowExceptionWhenTablesAndIgnoreTablesAreConfigured()
    {
        $config = $this->defaultDailyConfig();
        $config['tables'] = ['foo', 'bar', 'bam'];
        $config['ignore_tables'] = ['bar'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tables and ignore_tables cannot both be configured');
        new SnapshotPlan('daily', $config);
    }

    public function testSnapshotPlanWillThrowExceptionWhenSchemaOnlyTablesNotInTablesList()
    {
        $config = $this->defaultDailyConfig();
        $config['tables'] = ['foo', 'bam'];
        $config['schema_only_tables'] = ['bar'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('schema_only_tables that are configured must appear in tables as well');
        new SnapshotPlan('daily', $config);
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
