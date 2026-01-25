<?php

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Models\UserPreference;

class NotificationService
{
    /**
     * Check if notifications are enabled.
     */
    public function isEnabled(): bool
    {
        // Web mode doesn't support native notifications
        if (! config('orbit.multi_environment')) {
            return false;
        }

        // Default to enabled (opt-out)
        return UserPreference::getValue('notifications_enabled', true);
    }

    /**
     * Enable notifications.
     */
    public function enable(): void
    {
        UserPreference::setValue('notifications_enabled', true);
    }

    /**
     * Disable notifications.
     */
    public function disable(): void
    {
        UserPreference::setValue('notifications_enabled', false);
    }

    /**
     * Send a notification if enabled.
     */
    public function send(string $title, string $message, ?string $event = null): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        // Only use NativePHP Notification in desktop mode
        if (! class_exists(\Native\Laravel\Facades\Notification::class)) {
            return;
        }

        $notification = \Native\Laravel\Facades\Notification::title($title)
            ->message($message);

        if ($event) {
            $notification->event($event);
        }

        $notification->show();
    }

    /**
     * Send a success notification.
     */
    public function success(string $title, string $message): void
    {
        $this->send($title, $message);
    }

    /**
     * Send an error notification.
     */
    public function error(string $title, string $message): void
    {
        $this->send($title, $message);
    }

    /**
     * Notify about project creation success.
     */
    public function projectCreated(string $projectName): void
    {
        $this->success(
            'Project Created',
            "'{$projectName}' has been created successfully."
        );
    }

    /**
     * Notify about project deletion.
     */
    public function projectDeleted(string $projectName): void
    {
        $this->success(
            'Project Deleted',
            "'{$projectName}' has been deleted."
        );
    }

    /**
     * Notify about deployment update.
     */
    public function deploymentUpdated(string $projectName): void
    {
        $this->success(
            'Deployment Updated',
            "'{$projectName}' has been updated successfully."
        );
    }

    /**
     * Notify about deployment upgrade.
     */
    public function deploymentUpgraded(string $projectName): void
    {
        $this->success(
            'Deployment Upgraded',
            "'{$projectName}' has been upgraded to integrated mode."
        );
    }
}
