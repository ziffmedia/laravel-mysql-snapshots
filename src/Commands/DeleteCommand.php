<?php

namespace ZiffMedia\LaravelMysqlSnapshots;

use Illuminate\Console\Command;

class DeleteCommand extends Command
{
    protected $signature = 'mysql-snapshots:delete {--plan=}';
    protected $description = 'Delete MySQL Snapshot(s)';

    public function handle()
    {
        //
    }
}
