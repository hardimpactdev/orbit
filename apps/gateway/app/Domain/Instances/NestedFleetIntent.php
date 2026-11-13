<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmNestedFleet;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * The `fleet` part of a deployment intent of a nested-fleet default (docs/reference/development-containers.md#refresh-after-a-release),
 * the input of swamp/ansible/tasks/instance-fleet.yml. Only a default whose runtime keeps a fleet (InstancePlacement::keepsFleet) gets
 * one; every other deployment gets none and runs as before. The fleet's names and addresses are the task VM fleet's own
 * (TaskVmNestedFleet); `seed` is the commit of the seed that a failed refresh restores.
 */
final readonly class NestedFleetIntent
{
    /** Seconds that the fleet steps add to a deployment's run budget: start 600, refresh 3,600, restore 150 and the start after it 120. */
    public const int RunSeconds = 4470;

    /** @return array<string, mixed>|null */
    public function forDeployment(Instance $instance, InstanceRuntime $runtime): ?array
    {
        $instance->loadMissing(['project', 'node']);
        if ($runtime->t3_port !== null || ! InstancePlacement::keepsFleet($instance->project, $instance->node, $runtime->incus_project)) {
            return null;
        }
        $this->assertNoCopyInFlight($instance);
        $proxy = InstanceEgress::origin(Ipv4Subnet::from(ValidatedData::string($instance->node->instance_bridge))->usableRange()[0]);
        $local = implode(',', ['localhost', '127.0.0.1', '.orbit', TaskVmNestedFleet::WireGuardSubnet, TaskVmNestedFleet::Subnet]);

        return [
            'guests' => TaskVmNestedFleet::Guests,
            'layout' => array_map(static fn (array $guest): array => ['address' => $guest['address']], TaskVmNestedFleet::Layout),
            'operator' => TaskVmNestedFleet::operator(),
            'checkout' => ['path' => TaskVmNestedFleet::CheckoutPath],
            'proxy' => ['HTTP_PROXY' => $proxy, 'HTTPS_PROXY' => $proxy, 'http_proxy' => $proxy, 'https_proxy' => $proxy,
                'NO_PROXY' => $local, 'no_proxy' => $local],
            'seed' => $runtime->seed_commit,
        ];
    }

    /**
     * A refresh replaces the seed that a clone copies, so it is not admitted while the create of a clone of this default is open
     * (docs/reference/development-containers.md#refresh-after-a-release).
     */
    private function assertNoCopyInFlight(Instance $instance): void
    {
        $open = Operation::query()->where('kind', 'instance.create')->where('target_type', 'instance')
            ->whereNotIn('status', OperationStatus::terminalValues())
            ->whereIn('target_id', InstanceRuntime::query()->where('clone_source_instance_id', $instance->id)->select('instance_id'))
            ->exists();
        if ($open) {
            throw new ResourceOperationException('instance.clone_in_flight', 'A clone of the nested-fleet default is being created; the refresh waits until it ends.', 409);
        }
    }
}
