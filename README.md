<h1 align="center">
    Laravel Mysql Snapshots<br>
    <img alt="R" height="100" src="./docs/logo.png">
</h1>

## Installation

You can install the package via composer:

```bash
compose require ziffmedia/laravel-mysql-snapshots
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
artisan mysql-snapshot:list
```

#### Create Snapshots

```bash
artisan mysql-snapshot:create daily
```

To create snapshots, and automatically cleanup up old snapshots:

```bash
artisan mysql-snapshot:create daily --cleanup
```

#### Load Snapshots

To load the newest snapshot in the first available plan:

```bash
artisan mysql-snapshot:load
```

With Plan:

```bash
artisan mysql-snapshot:load daily
```