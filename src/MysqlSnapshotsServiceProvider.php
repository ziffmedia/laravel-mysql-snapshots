<?php

namespace ZiffMedia\LaravelMysqlSnapshots;

use Illuminate\Support\ServiceProvider;

class MysqlSnapshotsServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mysql-snapshots.php', 'mysql-snapshots');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\DeleteCommand::class,
                Commands\ClearCacheCommand::class,
                Commands\CreateCommand::class,
                Commands\ListCommand::class,
                Commands\LoadCommand::class
            ]);
        }
    }

    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/mysql-snapshots.php' => config_path('mysql-snapshots.php'),
        ], 'config');
    }
}
