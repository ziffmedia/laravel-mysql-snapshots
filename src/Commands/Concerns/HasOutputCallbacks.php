<?php

namespace ZiffMedia\LaravelMysqlSnapshots\Commands\Concerns;

use Closure;

trait HasOutputCallbacks
{
    protected ?Closure $progressCallback = null;

    protected ?Closure $messagingCallback = null;

    public function displayProgressUsing(callable $progressCallback): static
    {
        $this->progressCallback = $progressCallback;

        return $this;
    }

    protected function callProgress(int $current, int $total): void
    {
        if ($this->progressCallback) {
            ($this->progressCallback)($current, $total);
        }
    }

    public function displayMessagesUsing(callable $verboseCallback): static
    {
        $this->messagingCallback = $verboseCallback;

        return $this;
    }

    protected function callMessaging($message): void
    {
        if ($this->messagingCallback) {
            ($this->messagingCallback)($message);
        }
    }
}
