<?php

namespace ZiffMedia\LaravelMysqlSnapshots;

use Illuminate\Console\Command;

class LoadCommand extends Command
{
    protected $signature = 'mysql-snapshots:load {--plan=}';
    protected $description = 'Load MySQL Snapshot(s)';

    public function handle()
    {
        //
    }
}
