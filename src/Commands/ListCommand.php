<?php

namespace ZiffMedia\LaravelMysqlSnapshots;

use Illuminate\Console\Command;

class ListCommand extends Command
{
    protected $signature = 'mysql-snapshots:list {--plan=}';
    protected $description = 'List MySQL Snapshot(s)';

    public function handle()
    {
        //
    }
}
