<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions\Push;

use App\Models\Node;
use Orbit\Extensions\Conn\Models\ConnPushDevice;

/** Deletes one push device registration belonging to the calling Node. */
final readonly class DeleteConnPushDeviceAction
{
    public function execute(Node $node, string $deviceId): bool
    {
        return ConnPushDevice::query()
            ->where('node_id', $node->id)
            ->where('device_id', strtolower($deviceId))
            ->delete() > 0;
    }
}
