<?php

declare(strict_types=1);

namespace App\Services;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use LaravelZero\Framework\Commands\Command;

/**
 * Logger for project deletion operations in the CLI.
 *
 * Handles logging to files and broadcasting status updates
 * via Pusher SDK to Reverb WebSocket + console output.
 */
final class DeletionLogger implements ProvisionLoggerContract
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
        $logsDir = "{$home}/.config/orbit/logs/deletion";

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

        if (! $this->broadcaster?->isEnabled() || ! $this->slug) {
            return;
        }

        $data = [
            'slug' => $this->slug,
            'status' => $status,
            'timestamp' => date('c'),
        ];

        if ($error) {
            $data['error'] = $error;
        }

        // Broadcast to project-specific channel
        $this->broadcaster->broadcast(
            "project.{$this->slug}",
            'project.deletion.status',
            $data
        );

        // Also broadcast to global provisioning channel
        $this->broadcaster->broadcast(
            'provisioning',
            'project.deletion.status',
            $data
        );
    }

    /**
     * Get the slug for this logger instance.
     */
    public function getSlug(): string
    {
        return $this->slug ?? '';
    }

    /**
     * Get the project ID for this logger instance.
     */
    public function getProjectId(): ?int
    {
        return $this->projectId;
    }
}
