<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\Nodes\NodeUpdateBroadcaster;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecovery;
use App\Infrastructure\GatewayReleases\GatewayReleaseRestore;
use App\Models\GatewayRelease;
use App\Models\Node;
use Illuminate\Support\Facades\Event;
use Tests\Support\Fleet\FleetFixtures;

/**
 * The `updating` value of each `node.updated` event for the Node, in order.
 *
 * @return list<string|null>
 */
function nodeUpdatingBroadcasts(Node $node): array
{
    return Event::dispatched(
        RecordBroadcast::class,
        static fn (RecordBroadcast $event): bool => $event->type === RecordEventType::NodeUpdated && $event->id === $node->id,
    )->map(static function (array $dispatch): ?string {
        $updating = $dispatch[0]->data['updating'] ?? null;

        return is_array($updating) ? $updating['kind'] : null;
    })->values()->all();
}

function nodeUpdatingRecorder(): GatewayReleaseRecorder
{
    return new GatewayReleaseRecorder(app(GatewayReleaseRecovery::class), app(GatewayReleaseRestore::class),
        orbitHome: sys_get_temp_dir().'/orbit-node-updating-'.bin2hex(random_bytes(4)),
        nodes: app(NodeUpdateBroadcaster::class),
    );
}

describe('Node update broadcasts', function (): void {
    it('broadcasts the Gateway Node when a release starts running and when it fails', function (): void {
        $gateway = $this->markAsGateway(FleetFixtures::node('gateway'));
        $worker = FleetFixtures::node('dev', [RoleName::AppDev]);
        $recorder = nodeUpdatingRecorder();
        Event::fake([RecordBroadcast::class]);

        $record = $recorder->begin('deploy', str_repeat('c', 40));
        $recorder->fail($record, new GatewayReleaseException('prepare', 'gateway.release_failed', 'Prepare failed.'), retryable: true, durationMs: 10);

        expect(nodeUpdatingBroadcasts($gateway))->toBe(['gateway_release', null])
            ->and(nodeUpdatingBroadcasts($worker))->toBe([]);
    });

    it('broadcasts the Gateway Node when a queued release is claimed and when a dead one is interrupted', function (): void {
        $gateway = $this->markAsGateway(FleetFixtures::node('gateway'));
        $recorder = nodeUpdatingRecorder();
        $queued = $recorder->queue('deploy', str_repeat('d', 40), str_repeat('d', 40));
        Event::fake([RecordBroadcast::class]);

        $record = $recorder->begin('deploy', str_repeat('d', 40), $queued);
        $recorder->settle($record);

        expect($record->refresh()->outcome)->toBe(GatewayRelease::Interrupted)
            ->and(nodeUpdatingBroadcasts($gateway))->toBe(['gateway_release', null]);
    });

    it('wires the broadcasts into the container recorder', function (): void {
        $gateway = $this->markAsGateway(FleetFixtures::node('gateway'));
        Event::fake([RecordBroadcast::class]);

        app(GatewayReleaseRecorder::class)->begin('deploy', str_repeat('e', 40));

        expect(nodeUpdatingBroadcasts($gateway))->toBe(['gateway_release']);
    });
});
