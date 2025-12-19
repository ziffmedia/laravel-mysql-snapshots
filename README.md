<div align="center">
  <img src="art/logo.svg" alt="Laravel MySQL Snapshots" width="500">

  <p align="center">
    <strong>Create, manage, and load MySQL database snapshots with ease</strong>
  </p>

  <p align="center">
    <a href="https://packagist.org/packages/ziffmedia/laravel-mysql-snapshots"><img src="https://img.shields.io/packagist/v/ziffmedia/laravel-mysql-snapshots.svg?style=flat-square" alt="Latest Version on Packagist"></a>
    <a href="https://packagist.org/packages/ziffmedia/laravel-mysql-snapshots"><img src="https://img.shields.io/packagist/dt/ziffmedia/laravel-mysql-snapshots.svg?style=flat-square" alt="Total Downloads"></a>
    <a href="https://github.com/ziffmedia/laravel-mysql-snapshots/actions"><img src="https://img.shields.io/github/actions/workflow/status/ziffmedia/laravel-mysql-snapshots/run-tests.yml?branch=master&style=flat-square" alt="GitHub Tests Action Status"></a>
  </p>
</div>

---

## Overview

Laravel MySQL Snapshots is a powerful package that streamlines the process of creating, managing, and loading MySQL database snapshots in your Laravel applications. Perfect for syncing production data to local development environments, creating test fixtures, or maintaining database backups across different storage systems.

## Features

- **📸 Flexible Snapshot Plans** - Define multiple snapshot configurations with custom naming, tables, and options
- **☁️ Cloud Storage Integration** - Seamlessly store and retrieve snapshots from any Laravel filesystem disk
- **📊 Enhanced List Display** - View snapshots in formatted tables with file sizes and timestamps
- **⚡ Smart Caching** - Automatic timestamp-based cache validation for faster subsequent loads
- **📈 Progress Indicators** - Visual feedback for large snapshot downloads
- **🔧 Post-Load SQL Commands** - Execute custom SQL commands automatically after loading snapshots
- **👥 Plan Groups** - Batch operations on related plans with automatic detection
- **🔒 Environment Locks** - Restrict snapshot creation/loading to specific environments
- **🗂️ Partial Snapshots** - Include/exclude specific tables or use schema-only dumps
- **🧹 Automatic Cleanup** - Keep only the most recent N snapshots per plan

## Installation

Install the package via Composer:

```bash
composer require ziffmedia/laravel-mysql-snapshots
```

Publish the configuration file:

```bash
php artisan vendor:publish --provider='ZiffMedia\LaravelMysqlSnapshots\MysqlSnapshotsServiceProvider'
```

This will create a `config/mysql-snapshots.php` file in your application.

## Configuration

The configuration file allows you to define snapshot plans, storage locations, and behavior. Here's an overview of the key configuration options:

### Basic Configuration Structure

```php
return [
    'cache_by_default' => false,  // Enable smart caching

    'filesystem' => [
        'local_disk'   => 'local',      // Local disk for caching
        'local_path'   => 'mysql-snapshots',
        'archive_disk' => 'cloud',      // Cloud disk for storage
        'archive_path' => 'mysql-snapshots',
    ],

    // Global SQL commands to run after ANY snapshot load
    'post_load_sqls' => [
        // 'UPDATE users SET environment = "local"',
    ],

    // Plan groups: Named groups of plans for batch operations
    'plan_groups' => [
        // 'daily' => [
        //     'plans' => ['daily-base', 'daily-extra'],
        // ],
    ],

    'plans' => [
        'daily' => [
            'connection'         => null,  // Database connection (null = default)
            'file_template'      => 'mysql-snapshot-daily-{date:Ymd}',
            'mysqldump_options'  => '--single-transaction --no-tablespaces',
            'tables'             => [],    // Empty = all tables
            'ignore_tables'      => [],
            'schema_only_tables' => ['failed_jobs'],  // Only dump structure
            'keep_last'          => 7,     // Keep last N snapshots
            'environment_locks'  => [
                'create' => 'production',  // Only create in production
                'load'   => 'local',       // Only load in local
            ],
            'post_load_sqls' => [
                // Plan-specific SQL commands
            ],
        ],
    ],

    'utilities' => [
        'mysqldump' => 'mysqldump',
        'mysql'     => 'mysql',
        'zcat'      => 'zcat',
        'gzip'      => 'gzip',
    ],
];
```

### Configuration Options Explained

#### Global Options

- `cache_by_default` - Enable automatic timestamp-based cache validation

#### Filesystem

- `local_disk` - Laravel disk for local caching (default: `local`)
- `local_path` - Path on local disk for cached snapshots
- `archive_disk` - Laravel disk for archived snapshots (typically cloud storage)
- `archive_path` - Path on archive disk

#### Plans

Each plan can have the following options:

- `connection` - Database connection name (null for default)
- `file_template` - Snapshot filename template (supports `{date:format}` placeholder)
- `mysqldump_options` - Additional options passed to mysqldump
- `tables` - Array of specific tables to include (empty = all tables)
- `ignore_tables` - Array of tables to exclude
- `schema_only_tables` - Array of tables to dump structure only (no data)
- `keep_last` - Number of snapshots to retain (older ones are deleted)
- `environment_locks` - Restrict operations to specific environments
  - `create` - Environment(s) where snapshots can be created
  - `load` - Environment(s) where snapshots can be loaded
- `post_load_sqls` - Array of SQL commands to execute after loading

## Usage

### List Snapshots

View all available snapshots with file sizes and timestamps:

```bash
php artisan mysql-snapshots:list
```

View snapshots for a specific plan:

```bash
php artisan mysql-snapshots:list daily
```

Example output:
```
Plan: daily

┌───┬────────────────────────────────────┬─────────────────────┬──────────┐
│ # │ Filename                           │ Created             │ Size     │
├───┼────────────────────────────────────┼─────────────────────┼──────────┤
│ 1 │ mysql-snapshot-daily-20250115.gz   │ 2025-01-15 10:30:00 │ 125.4 MB │
│ 2 │ mysql-snapshot-daily-20250114.gz   │ 2025-01-14 10:30:00 │ 123.8 MB │
└───┴────────────────────────────────────┴─────────────────────┴──────────┘
```

### Create Snapshots

Create a snapshot using the specified plan:

```bash
php artisan mysql-snapshots:create daily
```

Create a snapshot and automatically cleanup old ones:

```bash
php artisan mysql-snapshots:create daily --cleanup
```

Create snapshots for all plans in a plan group:

```bash
php artisan mysql-snapshots:create daily-group
```

### Load Snapshots

Load the newest snapshot from the first available plan:

```bash
php artisan mysql-snapshots:load
```

Load a specific plan:

```bash
php artisan mysql-snapshots:load daily
```

Load with caching (keeps local copy for faster subsequent loads):

```bash
php artisan mysql-snapshots:load daily --cached
```

Download fresh snapshot and keep it cached:

```bash
php artisan mysql-snapshots:load daily --recached
```

Load without dropping existing tables:

```bash
php artisan mysql-snapshots:load daily --no-drop
```

Skip post-load SQL commands:

```bash
php artisan mysql-snapshots:load daily --skip-post-commands
```

Load all plans in a plan group sequentially:

```bash
php artisan mysql-snapshots:load daily-group
```

## Advanced Features

### Smart Caching

Enable smart caching to automatically validate cached snapshots based on timestamps:

```php
'cache_by_default' => true,
```

When enabled, the system stores metadata (`.meta.json` files) alongside cached snapshots. On subsequent loads, it checks if the archive file is newer than the cached version and automatically refreshes if needed.

### Post-Load SQL Commands

Execute SQL commands automatically after loading snapshots. Useful for environment-specific adjustments. Commands execute in this order:

1. **Global commands** - Run after each individual plan loads
2. **Plan-specific commands** - Run after the specific plan loads
3. **Plan group commands** - Run after all plans in a group have loaded

**Global commands** (run after any snapshot load):
```php
'post_load_sqls' => [
    'UPDATE users SET email = CONCAT("user+", id, "@example.test") WHERE is_admin = 0',
    'ANALYZE TABLE users, orders, products',
],
```

**Plan-specific commands** (run after loading specific plan):
```php
'plans' => [
    'daily' => [
        // ...
        'post_load_sqls' => [
            'UPDATE settings SET environment = "local"',
            'DELETE FROM cache WHERE expires_at < NOW()',
        ],
    ],
],
```

**Plan group commands** (run after all plans in the group have loaded):
```php
'plan_groups' => [
    'daily' => [
        'plans' => ['daily-base', 'daily-extra'],
        'post_load_sqls' => [
            'ANALYZE TABLE users, orders',  // Run after both plans are loaded
            'OPTIMIZE TABLE products',
        ],
    ],
],
```

### Plan Groups

Group related plans for batch operations:

```php
'plan_groups' => [
    'daily' => [
        'plans' => ['daily-base', 'daily-savings-partial'],
        'post_load_sqls' => [
            // Optional: SQL commands to run after ALL plans in group are loaded
            'ANALYZE TABLE users',
        ],
    ],
],
```

Then operate on all plans in the group:

```bash
# System automatically detects "daily" is a plan group
php artisan mysql-snapshots:create daily
php artisan mysql-snapshots:load daily
```

### Progress Indicators

Large snapshot downloads automatically display progress bars with download speed and percentage:

```
Loading mysql-snapshot-daily-20250115.gz...
 125 MB/250 MB [▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░░░░░░] 50% 5.2 MB/s
```

### MariaDB Support

If you're using MariaDB instead of MySQL, you'll need to adjust your `mysqldump_options` since MariaDB's `mysqldump` doesn't support certain MySQL-specific flags.

**MySQL 8.0+ recommended options:**
```php
'mysqldump_options' => '--single-transaction --no-tablespaces --set-gtid-purged=OFF --column-statistics=0',
```

**MariaDB recommended options:**
```php
'mysqldump_options' => '--single-transaction --no-tablespaces',
```

The following flags are MySQL-specific and will cause errors with MariaDB:
- `--set-gtid-purged` - MySQL GTID replication feature
- `--column-statistics` - MySQL 8.0+ histogram statistics feature

## Use Cases & Examples

### Use Case 1: Simple Daily Production Sync

**Scenario:** Sync production database to local development daily.

```php
'plans' => [
    'daily' => [
        'connection' => null,
        'file_template' => 'mysql-snapshot-daily-{date:Ymd}',
        'mysqldump_options' => '--single-transaction --no-tablespaces --set-gtid-purged=OFF --column-statistics=0',
        'schema_only_tables' => ['failed_jobs'],
        'keep_last' => 7,
        'environment_locks' => [
            'create' => 'production',
            'load' => 'local',
        ],
        'post_load_sqls' => [
            'UPDATE users SET email = CONCAT("user+", id, "@test.local")',
        ],
    ],
],
```

**Workflow:**
```bash
# On production (automated via cron)
php artisan mysql-snapshots:create daily --cleanup

# On local
php artisan mysql-snapshots:load daily --cached
```

### Use Case 2: Split Large Database

**Scenario:** Production database is too large. Split into base data and a filtered subset of large table.

```php
'plan_groups' => [
    'daily' => [
        'plans' => ['daily-base', 'daily-transactions-partial'],
    ],
],

'plans' => [
    'daily-base' => [
        'connection' => null,
        'file_template' => 'mysql-snapshot-daily-base-{date:Ymd}',
        'mysqldump_options' => '--single-transaction --no-tablespaces --set-gtid-purged=OFF --column-statistics=0 --skip-lock-tables',
        'ignore_tables' => ['transactions'],  // Exclude large table
        'keep_last' => 1,
        'environment_locks' => [
            'create' => 'production',
            'load' => 'local',
        ],
    ],

    'daily-transactions-partial' => [
        'connection' => null,
        'file_template' => 'mysql-snapshot-daily-transactions-partial-{date:Ymd}',
        'mysqldump_options' => '--single-transaction --no-tablespaces --set-gtid-purged=OFF --column-statistics=0 --skip-lock-tables --where="created_at >= \'2025-01-01\'"',
        'tables' => ['transactions'],  // Only this table
        'keep_last' => 1,
        'environment_locks' => [
            'create' => 'production',
            'load' => 'local',
        ],
    ],
],
```

**Workflow:**
```bash
# On production (automated)
php artisan mysql-snapshots:create daily  # Creates both plans

# On local (system auto-detects plan group and loads both)
php artisan mysql-snapshots:load daily --cached
```

### Use Case 3: Multiple Environments with Different Data

**Scenario:** Maintain separate snapshots for staging and production, with environment-specific post-load adjustments.

```php
'plans' => [
    'production-daily' => [
        'connection' => null,
        'file_template' => 'mysql-snapshot-production-{date:Ymd}',
        'mysqldump_options' => '--single-transaction --no-tablespaces',
        'keep_last' => 7,
        'environment_locks' => [
            'create' => 'production',
            'load' => ['local', 'testing'],
        ],
        'post_load_sqls' => [
            'UPDATE settings SET app_env = "local"',
            'UPDATE users SET email = CONCAT("user+", id, "@test.local") WHERE role != "admin"',
            'TRUNCATE TABLE sessions',
        ],
    ],

    'staging-daily' => [
        'connection' => null,
        'file_template' => 'mysql-snapshot-staging-{date:Ymd}',
        'mysqldump_options' => '--single-transaction --no-tablespaces',
        'keep_last' => 3,
        'environment_locks' => [
            'create' => 'staging',
            'load' => ['local', 'testing'],
        ],
    ],
],
```

### Use Case 4: Testing with Specific Fixtures

**Scenario:** Create specialized snapshots for different test scenarios.

```php
'plans' => [
    'test-base' => [
        'connection' => 'testing',
        'file_template' => 'test-base-{date:Ymd}',
        'mysqldump_options' => '--single-transaction',
        'keep_last' => 1,
        'environment_locks' => [
            'create' => 'local',
            'load' => ['local', 'testing'],
        ],
    ],

    'test-with-orders' => [
        'connection' => 'testing',
        'file_template' => 'test-orders-{date:Ymd}',
        'tables' => ['users', 'orders', 'order_items', 'products'],
        'keep_last' => 1,
        'environment_locks' => [
            'create' => 'local',
            'load' => ['local', 'testing'],
        ],
    ],
],
```

### Use Case 5: Optimized Performance Snapshots

**Scenario:** Large database with optimizations for faster dumps and loads.

```php
'plans' => [
    'daily-full' => [
        'connection' => null,
        'file_template' => 'mysql-snapshot-daily-{date:Ymd}',
        'mysqldump_options' => '--single-transaction --no-tablespaces --set-gtid-purged=OFF --column-statistics=0',
        'schema_only_tables' => ['failed_jobs', 'telescope_entries', 'activity_log'],
        'ignore_tables' => ['sessions', 'cache'],
        'keep_last' => 1,
        'environment_locks' => [
            'create' => 'production',
            'load' => 'local',
        ],
        'post_load_sqls' => [
            'ANALYZE TABLE users',
            'ANALYZE TABLE orders',
            'ANALYZE TABLE products',
        ],
    ],
],

'cache_by_default' => true,  // Enable smart caching
```

**Workflow:**
```bash
# First load (downloads from cloud)
php artisan mysql-snapshots:load daily-full --cached

# Subsequent loads (uses cached copy, very fast)
php artisan mysql-snapshots:load daily-full --cached

# When new snapshot available (automatically detects and refreshes)
php artisan mysql-snapshots:load daily-full --cached
```

## Real-World Configuration Example

Here's a complete configuration from a production application with a large database:

```php
<?php

return [
    'cache_by_default' => true,

    'filesystem' => [
        'local_disk' => 'local',
        'local_path' => 'mysql-snapshots',
        'archive_disk' => 'cloud',
        'archive_path' => 'mysql-snapshots',
    ],

    'post_load_sqls' => [
        'SET FOREIGN_KEY_CHECKS=1',
    ],

    'plan_groups' => [
        'daily' => [
            'plans' => ['daily-base', 'daily-transactions-partial'],
            'post_load_sqls' => [
                'ANALYZE TABLE users',
            ],
        ],
    ],

    'plans' => [
        'daily-base' => [
            'connection' => null,
            'file_template' => 'mysql-snapshot-daily-base-{date:Ymd}',
            'mysqldump_options' => '--single-transaction --no-tablespaces --set-gtid-purged=OFF --column-statistics=0 --skip-lock-tables',
            'tables' => [],
            'ignore_tables' => ['transactions'],
            'keep_last' => 1,
            'environment_locks' => [
                'create' => 'production',
                'load' => 'local',
            ],
            'post_load_sqls' => [
                'UPDATE users SET email = CONCAT("dev+", id, "@company.local") WHERE is_admin = 0',
            ],
        ],

        'daily-transactions-partial' => [
            'connection' => null,
            'file_template' => 'mysql-snapshot-daily-transactions-partial-{date:Ymd}',
            'mysqldump_options' => '--single-transaction --no-tablespaces --set-gtid-purged=OFF --column-statistics=0 --skip-lock-tables --where="created_at >= \'2025-01-01\'"',
            'tables' => ['transactions'],
            'keep_last' => 1,
            'environment_locks' => [
                'create' => 'production',
                'load' => 'local',
            ],
        ],

        'daily-full' => [
            'connection' => null,
            'file_template' => 'mysql-snapshot-daily-{date:Ymd}',
            'mysqldump_options' => '--single-transaction --no-tablespaces --set-gtid-purged=OFF --column-statistics=0',
            'schema_only_tables' => ['failed_jobs'],
            'tables' => [],
            'keep_last' => 1,
            'environment_locks' => [
                'create' => 'production',
                'load' => 'local',
            ],
        ],
    ],

    'utilities' => [
        'mysqldump' => 'mysqldump',
        'mysql' => 'mysql',
        'zcat' => 'zcat',
        'gzip' => 'gzip',
    ],
];
```

## Automating Snapshot Creation

### Using Laravel Scheduler

Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Create daily snapshot at 2 AM
    $schedule->command('mysql-snapshots:create daily --cleanup')
        ->dailyAt('02:00')
        ->onOneServer()
        ->environments(['production']);
}
```

### Using Cron

```bash
# Create snapshot daily at 2 AM
0 2 * * * cd /path/to/app && php artisan mysql-snapshots:create daily --cleanup
```

## Testing

Run the test suite:

```bash
phpunit
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Security

If you discover any security related issues, please email security@ziffmedia.com instead of using the issue tracker.

## Credits

- [Ziff Media](https://github.com/ziffmedia)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
