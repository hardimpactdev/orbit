<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * The `fleet` part of a deployment intent of a nested-fleet default (docs/reference/development-containers.md#refresh-after-a-release)
 * and of a create intent of its clone (docs/reference/development-containers.md#a-clone-starts-its-fleet), the input of
 * swamp/ansible/tasks/instance-fleet.yml. Only an Instance whose runtime keeps a fleet (InstancePlacement::keepsFleet) gets one; every
 * other deployment or create gets none and runs as before. NestedFleetLayout owns the fleet's names and addresses.
 * A failed refresh restores the seed the run took first, so the intent names none.
 */
final readonly class NestedFleetIntent
{
    /**
     * Seconds that the fleet steps add to a deployment's run budget: the check 400 (the run's one fleet start), the first seed 120, the
     * refresh 4,200 (with the start of what it stopped for `base`), restore 150, the start after it 120 and the start of the restored
     * fleet 600.
     */
    public const int RunSeconds = 5590;

    /** @return array<string, mixed>|null */
    public function forDeployment(Instance $instance, InstanceRuntime $runtime): ?array
    {
        $instance->loadMissing(['project', 'node']);
        if ($runtime->t3_port !== null || ! InstancePlacement::keepsFleet($instance->project, $instance->node, $runtime->incus_project)) {
            return null;
        }
        self::assertNoCopyInFlight($instance);

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
        if (! self::cloneKeepsFleet($instance, $runtime)) {
            return null;
        }

        return $this->fleet($instance);
    }

    /**
     * Whether the Instance is a clone (its runtime has a T3 port) of a default whose runtime keeps a fleet: the one rule for a clone's
     * fleet start at its create and for its later start (Power\InstanceStart).
     */
    public static function cloneKeepsFleet(Instance $instance, ?InstanceRuntime $runtime): bool
    {
        $instance->loadMissing(['project', 'node']);

        return $runtime !== null && $runtime->t3_port !== null
            && InstancePlacement::keepsFleet($instance->project, $instance->node, $runtime->incus_project);
    }

    /**
     * The memory limit of each nested container of an Instance fleet, 11 GiB of the Instance container's 12 GiB: nested-app-dev hosts
     * Instances, app-prod serves no Instance (docs/reference/development-containers.md#instance-host-in-the-fleet).
     */
    public const array Memory = ['gateway' => '4GiB', 'app-dev' => '5GiB', 'app-prod' => '2GiB'];

    /** @return array<string, mixed> */
    private function fleet(Instance $instance): array
    {
        $proxy = InstanceEgress::origin(Ipv4Subnet::from(ValidatedData::string($instance->node->instance_bridge))->usableRange()[0]);
        // The nested Gateway's own address is named as well as its network: PHP's HTTP clients may not match an address to a range.
        $local = implode(',', ['localhost', '127.0.0.1', '::1', '.orbit', NestedFleetLayout::Layout['gateway']['wireguard'],
            NestedFleetLayout::WireGuardSubnet, NestedFleetLayout::Subnet]);

        $size = $instance->runtime()->value('idmap_size');
        $layout = [];
        foreach (NestedFleetLayout::Layout as $role => $guest) {
            $layout[$role] = ['address' => $guest['address'], 'memory' => self::Memory[$role]];
        }

        return [
            'guests' => NestedFleetLayout::Guests,
            'layout' => $layout,
            // The refresh builds the Instance host on nested-app-dev only when the default's ID map leaves room for isolated Instances
            // there (docs/reference/development-containers.md#instance-host-in-the-fleet).
            'host' => is_int($size) && $size >= InstanceHostIntent::NestedIdMapSize,
            'operator' => NestedFleetLayout::operator(),
            'checkout' => ['path' => NestedFleetLayout::CheckoutPath],
            'proxy' => ['HTTP_PROXY' => $proxy, 'HTTPS_PROXY' => $proxy, 'http_proxy' => $proxy, 'https_proxy' => $proxy,
                'NO_PROXY' => $local, 'no_proxy' => $local],
        ];
    }

    /**
     * A refresh replaces the seed that a clone copies, so it is not admitted while the create of a clone of this default is open
     * (docs/reference/development-containers.md#refresh-after-a-release); neither is a change of the default's inner volume record
     * (InstanceInnerVolumeCommand).
     */
    public static function assertNoCopyInFlight(Instance $instance): void
    {
        $open = Operation::query()->where('kind', 'instance.create')->where('target_type', 'instance')
            ->whereNotIn('status', OperationStatus::terminalValues())
            ->whereIn('target_id', InstanceRuntime::query()->where('clone_source_instance_id', $instance->id)->select('instance_id'))
            ->exists();
        if ($open) {
            throw new ResourceOperationException('instance.clone_in_flight', 'A clone of the nested-fleet default is being created; try again when it has ended.', 409);
        }
    }
}
