<?php

declare(strict_types=1);

namespace App\Domain\Broadcasting;

use DateTimeInterface;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Carbon;

/**
 * The single envelope broadcast for every record change: `{ type, id, at, data }`. `data` is the same Spatie Data
 * object shape the record's list/show endpoint returns, or a notice. Record events go out on the private `orbit`
 * channel; the progress of one operation also goes out on its own `private-operation.{id}` channel.
 */
final readonly class RecordBroadcast implements ShouldBroadcastNow
{
    public string $at;

    /** @param array<string, mixed> $data */
    public function __construct(
        public RecordEventType $type,
        public int|string $id,
        public array $data,
        public string $channel = 'orbit',
    ) {
        $this->at = Carbon::now()->format(DateTimeInterface::ATOM);
    }

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return $this->type->value;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type->value,
            'id' => $this->id,
            'at' => $this->at,
            'data' => $this->data,
        ];
    }
}
