<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Provision;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Events\ProjectProvisioningStatus;
use Illuminate\Support\Facades\Log;

/**
 * Logger for project provisioning operations.
 *
 * Handles logging to Laravel logs and broadcasting status updates
 * via native Laravel events to Reverb WebSocket.
 *
 * Used by Horizon jobs for background provisioning.
 */
final class ProvisionLogger implements ProvisionLoggerContract
{
    private ?string $logFile = null;

    public function __construct(
        private readonly string $slug,
        private readonly ?int $projectId = null,
    ) {
        $this->initializeLogFile();
    }

    private function initializeLogFile(): void
    {
        $home = $_SERVER['HOME'] ?? '/home/orbit';
        $logsDir = "{$home}/.config/orbit/logs/provision";

        if (! is_dir($logsDir)) {
            @mkdir($logsDir, 0755, true);
        }

        $this->logFile = "{$logsDir}/{$this->slug}.log";

        // Clear previous log
        @file_put_contents($this->logFile, '');
    }

    /**
     * Log an info message.
     */
    public function info(string $message): void
    {
        $this->log($message);
        Log::info("[{$this->slug}] {$message}");
    }

    /**
     * Log a warning message.
     */
    public function warn(string $message): void
    {
        $this->log("WARNING: {$message}");
        Log::warning("[{$this->slug}] {$message}");
    }

    /**
     * Log an error message.
     */
    public function error(string $message): void
    {
        $this->log("ERROR: {$message}");
        Log::error("[{$this->slug}] {$message}");
    }

    /**
     * Write to the file log.
     */
    public function log(string $message): void
    {
        if ($this->logFile) {
            $timestamp = date('Y-m-d H:i:s');
            @file_put_contents(
                $this->logFile,
                "[{$timestamp}] {$message}\n",
                FILE_APPEND
            );
        }
    }

    /**
     * Broadcast a status update via native Laravel events.
     *
     * This dispatches a ProjectProvisioningStatus event which implements
     * ShouldBroadcastNow, sending immediately to Reverb without queueing.
     *
     * Broadcast failures are logged but don't stop provisioning - the project
     * will still be created even if WebSocket updates fail.
     */
    public function broadcast(string $status, ?string $error = null): void
    {
        $errorSuffix = $error ? " - Error: {$error}" : '';
        $this->log("Status: {$status}{$errorSuffix}");

        Log::info("Project {$this->slug}: {$status}", [
            'project_id' => $this->projectId,
            'error' => $error,
        ]);

        try {
            event(new ProjectProvisioningStatus(
                slug: $this->slug,
                status: $status,
                error: $error,
                projectId: $this->projectId,
            ));
        } catch (\Throwable $e) {
            // Log broadcast failure but don't stop provisioning
            Log::warning("Failed to broadcast status for {$this->slug}: {$e->getMessage()}");
        }
    }

    /**
     * Get the slug for this logger instance.
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * Get the project ID for this logger instance.
     */
    public function getProjectId(): ?int
    {
        return $this->projectId;
    }
}
