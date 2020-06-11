<?php

namespace ZiffMedia\LaravelMysqlSnapshots;

use Illuminate\Support\ServiceProvider;

class MysqlSnapshotsServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/mysql-snapshots.php' => config_path('mysql-snapshots.php'),
        ], 'config');
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mysql-snapshots.php', 'mysql-snapshots');
    }
}
