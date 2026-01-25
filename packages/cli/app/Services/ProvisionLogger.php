<?php

declare(strict_types=1);

namespace App\Services;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use LaravelZero\Framework\Commands\Command;

/**
 * CLI implementation of ProvisionLoggerContract.
 *
 * Outputs to console and broadcasts to Reverb via Pusher SDK.
 * Used for synchronous CLI project creation with real-time progress.
 */
final class ProvisionLogger implements ProvisionLoggerContract
{
    private ?string $logFile = null;

    public function __construct(
        private readonly ?ReverbBroadcaster $broadcaster = null,
        private readonly ?Command $command = null,
        private readonly ?string $slug = null,
        private readonly ?int $projectId = null,
    ) {
        if ($this->slug) {
            $this->initializeLogFile();
        }
    }

    private function initializeLogFile(): void
    {
        $home = $_SERVER['HOME'] ?? '/home/orbit';
        $logsDir = "{$home}/.config/orbit/logs/provision";

        if (! is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }

        $this->logFile = "{$logsDir}/{$this->slug}.log";

        // Clear previous log
        file_put_contents($this->logFile, '');
    }

    public function info(string $message): void
    {
        $this->log($message);
        $this->command?->info($message);
    }

    public function warn(string $message): void
    {
        $this->log("WARNING: {$message}");
        $this->command?->warn($message);
    }

    public function error(string $message): void
    {
        $this->log("ERROR: {$message}");
        $this->command?->error($message);
    }

    public function log(string $message): void
    {
        if ($this->logFile) {
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents(
                $this->logFile,
                "[{$timestamp}] {$message}\n",
                FILE_APPEND
            );
        }
    }

    public function broadcast(string $status, ?string $error = null): void
    {
        $errorSuffix = $error ? " - Error: {$error}" : '';
        $this->log("Status: {$status}{$errorSuffix}");

        // Also output status to console
        $this->command?->info("→ {$status}");

        if (! $this->broadcaster?->isEnabled() || ! $this->slug) {
            return;
        }

        $data = [
            'slug' => $this->slug,
            'status' => $status,
        ];

        if ($this->projectId) {
            $data['project_id'] = $this->projectId;
        }

        if ($error) {
            $data['error'] = $error;
        }

        // Broadcast to project-specific channel
        $this->broadcaster->broadcast(
            "project.{$this->slug}",
            'project.provision.status',
            $data
        );

        // Also broadcast to global provisioning channel
        $this->broadcaster->broadcast(
            'provisioning',
            'project.provision.status',
            $data
        );
    }

    public function getSlug(): string
    {
        return $this->slug ?? '';
    }

    public function getProjectId(): ?int
    {
        return $this->projectId;
    }
}
