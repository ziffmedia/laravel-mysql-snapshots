<h1 align="center">
    Laravel Mysql Snapshots<br>
    <img alt="R" height="100" src="./docs/logo.png">
</h1>

## Installation

You can install the package via composer:

```bash
composer require ziffmedia/laravel-mysql-snapshots
```

You can publish the config file with:

```bash
php artisan vendor:publish --provider='ZiffMedia\LaravelMysqlSnapshots\MysqlSnapshotsServiceProvider'
```

Note: the configuration file will lock writing new snapshots to disk to the `production` environment
while loading snapshots will be locked to the `local` environment.

## Usage

#### List Snapshots

```bash
artisan mysql-snapshots:list
```

#### Create Snapshots

```bash
artisan mysql-snapshots:create daily
```

To create snapshots, and automatically cleanup up old snapshots:

```bash
artisan mysql-snapshots:create daily --cleanup
```

#### Load Snapshots

To load the newest snapshot in the first available plan:

```bash
artisan mysql-snapshots:load
```

With Plan:

```bash
artisan mysql-snapshots:load daily
```

**Additional options**

`--cached` Keeps a copy of the snapshot so that you don't need to redownload it on the next run

`--recached` Downloads a fresh sql file, even if one exists locally, and then keeps it cached

`--no-drop` Do not drop all tables in the database before loading the snapshot
