<?php

namespace ZiffMedia\LaravelMysqlSnapshots\Commands\Concerns;

use ZiffMedia\LaravelMysqlSnapshots\SnapshotPlan;

trait HasCommandHelpers
{
    protected function warnAboutUnacceptedFiles(): void
    {
        if (count(SnapshotPlan::$unacceptedFiles) > 0) {
            $this->newLine();
            $this->warn('Warning: Found ' . count(SnapshotPlan::$unacceptedFiles) . ' file(s) in the archive that do not match any configured plan:');

            foreach (SnapshotPlan::$unacceptedFiles as $unacceptedFile) {
                $this->line("  {$unacceptedFile}");
            }

            $this->line('');
            $this->line('These files may be from removed plans and can be safely deleted if no longer needed.');
        }
    }
}
