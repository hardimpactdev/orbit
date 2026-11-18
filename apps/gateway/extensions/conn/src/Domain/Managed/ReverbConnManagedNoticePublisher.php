<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Managed;

use App\Domain\Broadcasting\RealtimeConnection;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the notice to the serving Reverb and returns once Reverb answered. A failure is logged and leaves the notice
 * unsent: the target's next probe finds the revision ([Revision order](/reference/conn#revision-order)).
 */
final readonly class ReverbConnManagedNoticePublisher implements ConnManagedNoticePublisher
{
    public function __construct(private RealtimeConnection $realtime) {}

    public function publish(string $channel, array $data): bool
    {
        try {
            $connection = $this->realtime->resolve();
            if ($connection === null || ! $this->realtime->configureBroadcasting($connection)) {
                return false;
            }
            Broadcast::purge('reverb');
            $broadcaster = Broadcast::connection('reverb');
            if (! $broadcaster instanceof Broadcaster) {
                return false;
            }
            $broadcaster->broadcast([new PrivateChannel(substr($channel, strlen('private-')))], 'conn.authority.revoked', $data);

            return true;
        } catch (Throwable $exception) {
            Log::warning('Failed to publish a managed revocation notice.', ['channel' => $channel, 'exception' => $exception->getMessage()]);

            return false;
        }
    }
}
