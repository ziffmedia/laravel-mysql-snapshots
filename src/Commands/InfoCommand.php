<?php

namespace ZiffMedia\LaravelMysqlSnapshots\Commands;

use Illuminate\Console\Command;

class InfoCommand extends Command
{
    protected $signature = 'mysql-snapshots:info {plan}';
    protected $description = 'Check ?';

    public function handle()
    {
        /**
         * Create a table with:
         * - all tables
         * - ignored tables
         * - scheme only tables
         */
    }
}
