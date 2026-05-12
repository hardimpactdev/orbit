<?php

declare(strict_types=1);

namespace App\Support\Cli;

use App\Contracts\ProgressReporter;

final class RemoteProgressReporter implements ProgressReporter
{
    private bool $started = false;

    public function __construct(
        private readonly RemoteProgressRenderer $renderer,
    ) {}

    /**
     * @param  list<array{key: string, label: string, doneLabel?: string}>  $steps
     */
    public function tree(string $title, array $steps): void
    {
        $this->started = true;
        $this->renderer->tree($title, $steps);
    }

    public function stepStart(string $key): void
    {
        $this->renderer->step($key, 'start');
    }

    public function stepProgress(string $key, string $status, ?string $message = null): void
    {
        $this->renderer->step($key, $status, $message);
    }

    public function stepDone(string $key, ?string $message = null): void
    {
        $this->renderer->step($key, 'done', $message);
    }

    public function stepFail(string $key, string $message): void
    {
        $this->renderer->step($key, 'fail', $message);
    }

    public function stepSkip(string $key, ?string $message = null): void
    {
        $this->renderer->step($key, 'skip', $message);
    }

    public function finish(string $footer, bool $success): void
    {
        if (! $this->started) {
            return;
        }

        $this->renderer->finish($footer, $success);
    }
}
