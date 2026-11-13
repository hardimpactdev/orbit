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
 * The `fleet` part of a deployment intent of a nested-fleet default (docs/reference/development-containers.md#refresh-after-a-release)
 * and of a create intent of its clone (docs/reference/development-containers.md#a-clone-starts-its-fleet), the input of
 * swamp/ansible/tasks/instance-fleet.yml. Only an Instance whose runtime keeps a fleet (InstancePlacement::keepsFleet) gets one; every
 * other deployment or create gets none and runs as before. The fleet's names and addresses are the task VM fleet's own
 * (TaskVmNestedFleet). A failed refresh restores the seed the run took first, so the intent names none.
 */
final readonly class NestedFleetIntent
{
    /**
     * Seconds that the fleet steps add to a deployment's run budget: the check 300, the first seed 120, start 300, refresh 3,600, the
     * start of the refreshed fleet 600, restore 150, the start after it 120 and the start of the restored fleet 600.
     */
    public const int RunSeconds = 5790;

    /** @return array<string, mixed>|null */
    public function forDeployment(Instance $instance, InstanceRuntime $runtime): ?array
    {
        $instance->loadMissing(['project', 'node']);
        if ($runtime->t3_port !== null || ! InstancePlacement::keepsFleet($instance->project, $instance->node, $runtime->incus_project)) {
            return null;
        }
        $this->assertNoCopyInFlight($instance);

        return $this->fleet($instance);
    }

    /**
     * The `fleet` of a clone's create intent: a clone (its runtime has a T3 port) of a default that keeps a fleet starts the fleet it
     * copied and waits for its readiness. Every other create gets none.
     *
     * @return array<string, mixed>|null
     */
    public function forClone(Instance $instance, InstanceRuntime $runtime): ?array
    {
        $instance->loadMissing(['project', 'node']);
        if ($runtime->t3_port === null || ! InstancePlacement::keepsFleet($instance->project, $instance->node, $runtime->incus_project)) {
            return null;
        }

        return $this->fleet($instance);
    }

    /** @return array<string, mixed> */
    private function fleet(Instance $instance): array
    {
        $proxy = InstanceEgress::origin(Ipv4Subnet::from(ValidatedData::string($instance->node->instance_bridge))->usableRange()[0]);
        // The nested Gateway's own address is named as well as its network: PHP's HTTP clients may not match an address to a range.
        $local = implode(',', ['localhost', '127.0.0.1', '::1', '.orbit', TaskVmNestedFleet::Layout['gateway']['wireguard'],
            TaskVmNestedFleet::WireGuardSubnet, TaskVmNestedFleet::Subnet]);

        return [
            'guests' => TaskVmNestedFleet::Guests,
            'layout' => array_map(static fn (array $guest): array => ['address' => $guest['address']], TaskVmNestedFleet::Layout),
            'operator' => TaskVmNestedFleet::operator(),
            'checkout' => ['path' => TaskVmNestedFleet::CheckoutPath],
            'proxy' => ['HTTP_PROXY' => $proxy, 'HTTPS_PROXY' => $proxy, 'http_proxy' => $proxy, 'https_proxy' => $proxy,
                'NO_PROXY' => $local, 'no_proxy' => $local],
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
