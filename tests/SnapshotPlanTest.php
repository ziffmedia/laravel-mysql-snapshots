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

        // Reset static unacceptedFiles array
        SnapshotPlan::$unacceptedFiles = [];
    }

    protected function tearDown(): void
    {
        $this->cleanupFiles();

        parent::tearDown();
    }

    public function test_get_all_plans_based_off_config()
    {
        config()->set('mysql-snapshots.plans', [
            'daily'   => [],
            'monthly' => [],
        ]);

        $plans = SnapshotPlan::all();

        $this->assertCount(2, $plans);
        $this->assertCount(2, $plans->whereInstanceOf(SnapshotPlan::class));
    }

    public function test_get_settings()
    {
        $plan = new SnapshotPlan('daily', $this->defaultDailyConfig());

        $settingsFromPlan = $plan->getSettings();
        $this->assertEquals('daily', $settingsFromPlan['name']);
        $this->assertEquals('mysql-snapshot-daily-{date|Ymd}', $settingsFromPlan['file_template']);
        $this->assertEquals('--single-transaction', $settingsFromPlan['mysqldump_options']);
        $this->assertEquals(2, $settingsFromPlan['keep_last']);
        $this->assertEquals(['create' => 'production', 'load' => 'local'], $settingsFromPlan['environment_locks']);
    }

    public function test_can_create()
    {
        $plan = new SnapshotPlan('daily', $this->defaultDailyConfig());

        // set environment detection to return local
        app()->detectEnvironment(fn () => 'local');

        $this->assertFalse($plan->canCreate());

        // set environment detection to return production
        app()->detectEnvironment(fn () => 'production');

        $this->assertTrue($plan->canCreate());
    }

    public function test_can_load()
    {
        $plan = new SnapshotPlan('daily', $this->defaultDailyConfig());

        // set environment detection to return local
        app()->detectEnvironment(fn () => 'local');

        $this->assertTrue($plan->canLoad());

        // set environment detection to return production
        app()->detectEnvironment(fn () => 'production');

        $this->assertFalse($plan->canLoad());
    }

    public function test_create()
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

    public function test_create_with_table_list()
    {
        $config = $this->defaultDailyConfig();
        $config['tables'] = ['foo', 'bar', 'bam'];

        $snapshotPlan = new SnapshotPlan('daily', $config);
        $snapshot = $snapshotPlan->create();

        // assert command
        $this->assertStringContainsString('laravel foo bar bam', file_get_contents(__DIR__ . '/fixtures/local-filesystem/fakemysqldump-arguments.txt'));
    }

    public function test_create_with_table_list_and_schema_only()
    {
        $config = $this->defaultDailyConfig();
        $config['tables'] = ['foo', 'bar', 'bam'];
        $config['schema_only_tables'] = ['bar'];

        $snapshotPlan = new SnapshotPlan('daily', $config);
        $snapshot = $snapshotPlan->create();

        // assert command
        $this->assertStringContainsString('laravel foo bam', file_get_contents(__DIR__ . '/fixtures/local-filesystem/fakemysqldump-arguments.txt'));
        $this->assertStringContainsString('laravel bar', file_get_contents(__DIR__ . '/fixtures/local-filesystem/fakemysqldump-arguments.txt'));
    }

    public function test_snapshot_plan_will_throw_exception_when_tables_and_ignore_tables_are_configured()
    {
        $config = $this->defaultDailyConfig();
        $config['tables'] = ['foo', 'bar', 'bam'];
        $config['ignore_tables'] = ['bar'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tables and ignore_tables cannot both be configured');
        new SnapshotPlan('daily', $config);
    }

    public function test_snapshot_plan_will_throw_exception_when_schema_only_tables_not_in_tables_list()
    {
        $config = $this->defaultDailyConfig();
        $config['tables'] = ['foo', 'bam'];
        $config['schema_only_tables'] = ['bar'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('schema_only_tables that are configured must appear in tables as well');
        new SnapshotPlan('daily', $config);
    }

    public function test_snapshot_plan_handles_orphaned_files_from_removed_plans()
    {
        // Setup: Create snapshot files that match different plan patterns
        $archiveDisk = Storage::disk(config('mysql-snapshots.filesystem.archive_disk'));
        $archivePath = config('mysql-snapshots.filesystem.archive_path');

        // Create a file that matches a "daily-v8" plan pattern (which we'll pretend was removed)
        $orphanedFile = $archivePath . '/mysql-snapshot-daily-v8-20240913.sql.gz';
        $archiveDisk->put($orphanedFile, 'fake snapshot content');

        // Create a file that matches our current "daily" plan pattern
        $validFile = $archivePath . '/mysql-snapshot-daily-20240913.sql.gz';
        $archiveDisk->put($validFile, 'fake snapshot content');

        // Configure only the "daily" plan (simulating that "daily-v8" was removed)
        config()->set('mysql-snapshots.plans', [
            'daily' => $this->defaultDailyConfig(),
        ]);

        // This should not throw an exception despite the orphaned file
        $plans = SnapshotPlan::all();

        // Assert that we got our plan
        $this->assertCount(1, $plans);
        $dailyPlan = $plans->first();
        $this->assertEquals('daily', $dailyPlan->name);

        // Assert that only the matching file was accepted
        $this->assertCount(1, $dailyPlan->snapshots);
        $this->assertEquals('mysql-snapshot-daily-20240913.sql.gz', $dailyPlan->snapshots->first()->fileName);

        // Assert that the orphaned file was tracked as unaccepted
        $this->assertCount(1, SnapshotPlan::$unacceptedFiles);
        $this->assertStringContainsString('mysql-snapshot-daily-v8-20240913.sql.gz', SnapshotPlan::$unacceptedFiles[0]);
    }

    public function test_snapshot_can_get_size()
    {
        $snapshotPlan = new SnapshotPlan('daily', $this->defaultDailyConfig());
        $snapshot = $snapshotPlan->create();

        $size = $snapshot->getSize();
        $this->assertIsInt($size);
        $this->assertGreaterThan(0, $size);

        $formattedSize = $snapshot->getFormattedSize();
        $this->assertIsString($formattedSize);
        $this->assertMatchesRegularExpression('/\d+(\.\d+)?\s+(B|KB|MB|GB|TB)/', $formattedSize);
    }

    public function test_file_template_with_hour_format()
    {
        // Test creating a snapshot with hour format in file template
        $config = $this->defaultDailyConfig();
        $config['file_template'] = 'mysql-snapshot-hourly-{date:YmdH}';

        $snapshotPlan = new SnapshotPlan('hourly', $config);
        $snapshot = $snapshotPlan->create();

        // Expected filename should include the hour
        $expectedFileName = 'mysql-snapshot-hourly-' . date('YmdH') . '.sql.gz';
        $this->assertEquals($expectedFileName, $snapshot->fileName);

        // Test that the file was created
        $archiveDisk = Storage::disk(config('mysql-snapshots.filesystem.archive_disk'));
        $files = $archiveDisk->allFiles(config('mysql-snapshots.filesystem.archive_path'));
        $this->assertCount(1, $files);

        // Test that matchFileAndDate can parse the filename correctly
        $parsedDate = $snapshotPlan->matchFileAndDate($snapshot->fileName);
        $this->assertInstanceOf(\Carbon\Carbon::class, $parsedDate);
        $this->assertEquals(date('YmdH'), $parsedDate->format('YmdH'));
    }

    public function test_file_template_with_hour_and_minute_format()
    {
        // Test with hour and minute format
        $config = $this->defaultDailyConfig();
        $config['file_template'] = 'mysql-snapshot-{date:YmdHi}';

        $snapshotPlan = new SnapshotPlan('precise', $config);
        $snapshot = $snapshotPlan->create();

        $expectedFileName = 'mysql-snapshot-' . date('YmdHi') . '.sql.gz';
        $this->assertEquals($expectedFileName, $snapshot->fileName);

        // Test parsing
        $parsedDate = $snapshotPlan->matchFileAndDate($snapshot->fileName);
        $this->assertInstanceOf(\Carbon\Carbon::class, $parsedDate);
        $this->assertEquals(date('YmdHi'), $parsedDate->format('YmdHi'));
    }

    public function test_loading_snapshots_with_hour_format_from_disk()
    {
        // Create multiple snapshots with different hours
        $archiveDisk = Storage::disk(config('mysql-snapshots.filesystem.archive_disk'));
        $archivePath = config('mysql-snapshots.filesystem.archive_path');

        // Simulate snapshots from different hours
        $archiveDisk->put($archivePath . '/mysql-snapshot-hourly-2024091310.sql.gz', 'fake snapshot 10am');
        $archiveDisk->put($archivePath . '/mysql-snapshot-hourly-2024091314.sql.gz', 'fake snapshot 2pm');
        $archiveDisk->put($archivePath . '/mysql-snapshot-hourly-2024091318.sql.gz', 'fake snapshot 6pm');

        // Configure the plan
        config()->set('mysql-snapshots.plans', [
            'hourly' => [
                'connection'        => 'mysql',
                'file_template'     => 'mysql-snapshot-hourly-{date:YmdH}',
                'mysqldump_options' => '--single-transaction',
                'keep_last'         => 2,
                'environment_locks' => [
                    'create' => 'production',
                    'load'   => 'local',
                ],
            ],
        ]);

        // Load all plans and verify it found all three snapshots
        $plans = SnapshotPlan::all();
        $hourlyPlan = $plans->firstWhere('name', 'hourly');

        $this->assertCount(3, $hourlyPlan->snapshots);

        // Verify they are sorted newest first
        $this->assertEquals('mysql-snapshot-hourly-2024091318.sql.gz', $hourlyPlan->snapshots[0]->fileName);
        $this->assertEquals('mysql-snapshot-hourly-2024091314.sql.gz', $hourlyPlan->snapshots[1]->fileName);
        $this->assertEquals('mysql-snapshot-hourly-2024091310.sql.gz', $hourlyPlan->snapshots[2]->fileName);
    }

    public function test_file_template_with_date_and_time_separated()
    {
        // Test with date and time separated by dash (Ymd-His format)
        $config = $this->defaultDailyConfig();
        $config['file_template'] = 'mysql-snapshot-{date:Ymd-His}';

        $snapshotPlan = new SnapshotPlan('datetime', $config);
        $snapshot = $snapshotPlan->create();

        // Verify filename format matches expected pattern (date-time with dash separator)
        $this->assertMatchesRegularExpression(
            '/^mysql-snapshot-\d{8}-\d{6}\.sql\.gz$/',
            $snapshot->fileName,
            'Filename should match pattern: mysql-snapshot-YYYYMMDD-HHMMSS.sql.gz'
        );

        // Test that matchFileAndDate can parse the filename correctly
        $parsedDate = $snapshotPlan->matchFileAndDate($snapshot->fileName);
        $this->assertInstanceOf(\Carbon\Carbon::class, $parsedDate);

        // Use the snapshot's date property to verify parsing
        $expectedFormat = $snapshot->date->format('Ymd-His');
        $this->assertEquals($expectedFormat, $parsedDate->format('Ymd-His'));

        // Verify the parsed date components match the snapshot date
        $this->assertEquals($snapshot->date->format('Y'), $parsedDate->format('Y'));
        $this->assertEquals($snapshot->date->format('m'), $parsedDate->format('m'));
        $this->assertEquals($snapshot->date->format('d'), $parsedDate->format('d'));
        $this->assertEquals($snapshot->date->format('H'), $parsedDate->format('H'));
        $this->assertEquals($snapshot->date->format('i'), $parsedDate->format('i'));
        $this->assertEquals($snapshot->date->format('s'), $parsedDate->format('s'));
    }

    public function test_get_effective_mysqldump_options_returns_all_options_when_mysql_variant()
    {
        config()->set('mysql-snapshots.mysql_variant', 'mysql');

        $config = $this->defaultDailyConfig();
        $config['mysqldump_options'] = '--single-transaction --set-gtid-purged=OFF --column-statistics=0';

        $snapshotPlan = new SnapshotPlan('daily', $config);

        $this->assertEquals(
            '--single-transaction --set-gtid-purged=OFF --column-statistics=0',
            $snapshotPlan->getEffectiveMysqldumpOptions()
        );
    }

    public function test_get_effective_mysqldump_options_filters_mysql_only_options_when_mariadb_variant()
    {
        config()->set('mysql-snapshots.mysql_variant', 'mariadb');

        $config = $this->defaultDailyConfig();
        $config['mysqldump_options'] = '--single-transaction --no-tablespaces --set-gtid-purged=OFF --column-statistics=0';

        $snapshotPlan = new SnapshotPlan('daily', $config);

        $this->assertEquals(
            '--single-transaction --no-tablespaces',
            $snapshotPlan->getEffectiveMysqldumpOptions()
        );
    }

    public function test_get_effective_mysqldump_options_handles_all_gtid_values()
    {
        config()->set('mysql-snapshots.mysql_variant', 'mariadb');

        $testCases = [
            '--set-gtid-purged=OFF' => '',
            '--set-gtid-purged=ON' => '',
            '--set-gtid-purged=AUTO' => '',
            '--column-statistics=1' => '',
        ];

        foreach ($testCases as $mysqlOption => $expected) {
            $config = $this->defaultDailyConfig();
            $config['mysqldump_options'] = $mysqlOption;

            $snapshotPlan = new SnapshotPlan('daily', $config);

            $this->assertEquals(
                $expected,
                $snapshotPlan->getEffectiveMysqldumpOptions(),
                "Failed for option: $mysqlOption"
            );
        }
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
