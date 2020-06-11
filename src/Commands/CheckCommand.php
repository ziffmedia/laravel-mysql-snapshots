<?php

namespace ZiffMedia\LaravelMysqlSnapshots;

use Illuminate\Console\Command;

class CheckCommand extends Command
{
    protected $signature = 'mysql-snapshots:check {--plan=}';
    protected $description = 'Check ?';

    public function handle()
    {
        //
    }
}
