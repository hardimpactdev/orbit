<?php

declare(strict_types=1);

namespace App\Domain\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * An event that keeps its own Pusher name and payload instead of the record envelope, such as a Conn profile event
 * ([Profile events](/reference/conn#profile-events)). It goes out on one private channel.
 */
final readonly class NamedBroadcast implements ShouldBroadcastNow
{
    /**
     * @param  string  $channel  the private channel without its `private-` prefix
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $channel,
        public string $name,
        public array $payload,
    ) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
