<?php

declare(strict_types=1);

namespace App\Services\Install;

use LaravelZero\Framework\Commands\Command;

final readonly class InstallLogger
{
    public function __construct(
        private Command $command,
    ) {}

    public function title(string $message): void
    {
        $this->command->newLine();
        $this->command->line("<fg=blue;options=bold>{$message}</>");
        $this->command->newLine();
    }

    public function step(string $message): void
    {
        $this->command->line("  <fg=yellow>→</> {$message}");
    }

    public function progress(int $current, int $total, string $message): void
    {
        $this->command->line("<fg=gray>[{$current}/{$total}]</> {$message}");
    }

    public function success(string $message): void
    {
        $this->command->line("  <fg=green>✓</> {$message}");
    }

    public function skip(string $message): void
    {
        $this->command->line("  <fg=gray>○</> {$message}");
    }

    public function error(string $message): void
    {
        $this->command->line("  <fg=red>✗</> {$message}");
    }

    public function info(string $message): void
    {
        $this->command->line("  {$message}");
    }

    public function warn(string $message): void
    {
        $this->command->line("  <fg=yellow>⚠</> {$message}");
    }

    public function newLine(): void
    {
        $this->command->newLine();
    }
}
