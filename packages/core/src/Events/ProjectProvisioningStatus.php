<?php

namespace HardImpact\Orbit\Core\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts project provisioning status updates via Reverb.
 *
 * This event is dispatched during project creation to provide real-time
 * status updates to the frontend via WebSocket.
 *
 * Uses ShouldBroadcastNow to broadcast immediately without queueing,
 * ensuring real-time updates during the provisioning process.
 */
class ProjectProvisioningStatus implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public string $slug,
        public string $status,
        public ?string $error = null,
        public ?int $projectId = null,
    ) {}

    /**
     * Get the channel the event should broadcast on.
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
        return 'project.provision.status';
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
            'project_id' => $this->projectId,
        ];
    }
}
