<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Contracts;

/**
 * Contract for provision logging implementations.
 *
 * This interface allows different logging strategies:
 * - orbit-core: broadcasts via Laravel events (for Horizon jobs)
 * - orbit-cli: outputs to console + broadcasts via Pusher SDK
 */
interface ProvisionLoggerContract
{
    /**
     * Log an info message.
     */
    public function info(string $message): void;

    /**
     * Log a warning message.
     */
    public function warn(string $message): void;

    /**
     * Log an error message.
     */
    public function error(string $message): void;

    /**
     * Write to the file log.
     */
    public function log(string $message): void;

    /**
     * Broadcast a status update.
     *
     * Implementation depends on context:
     * - In Horizon jobs: broadcasts via Laravel events to Reverb
     * - In CLI: broadcasts via Pusher SDK to Reverb + console output
     */
    public function broadcast(string $status, ?string $error = null): void;

    /**
     * Get the slug for this logger instance.
     */
    public function getSlug(): string;

    /**
     * Get the project ID for this logger instance.
     */
    public function getProjectId(): ?int;
}
