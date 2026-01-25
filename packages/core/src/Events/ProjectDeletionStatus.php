<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Event for broadcasting project deletion status updates.
 *
 * Broadcasts immediately via Reverb for real-time UI updates.
 * Status values: deleting, removing_files, deleted, delete_failed
 */
class ProjectDeletionStatus implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public string $slug,
        public string $status,
        public ?string $error = null,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        return new Channel('provisioning');
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'project.deletion.status';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'slug' => $this->slug,
            'status' => $this->status,
            'error' => $this->error,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
