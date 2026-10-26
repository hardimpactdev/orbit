<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmHost;
use App\Models\Node;

/** Host-local builder lifecycle, smoke checks, and base alias promotion for recipe builds. */
final readonly class IncusTaskVmBaseImage
{
    private const int BootSeconds = 600;

    public function __construct(
        private IncusHost $incus,
        private GuestBootstrap $bootstrap,
        private TaskVmImageRecipe $recipe,
    ) {}

    /** Deletes builder and smoke VMs that an interrupted recipe build left. */
    public function sweep(TaskVmHost $host, Node $node, bool $discardFailed = false): void
    {
        foreach (array_keys($this->instances($host, $node)) as $name) {
            if (str_starts_with($name, TaskVmImageRecipe::WorkPrefix) && TaskVmHostAudit::claimBuildCleanup($node, $name, $discardFailed)) {
                $this->delete($host, $node, $name);
            }
        }
    }

    public function launch(TaskVmHost $host, Node $node, string $name, string $image, string $publicKey, bool $builder): void
    {
        $this->incus->changeVm($host, $node, $name, [
            'kind' => 'create_image', 'image' => $image, 'gateway_public_key' => $publicKey,
            'cpus' => $host->cpus, 'memory' => $host->memory, 'disk' => $host->disk,
            'network' => $host->network, 'builder' => $builder,
        ]);
    }

    /** The guest shell of the VM `$name` on the host. */
    public function shell(TaskVmHost $host, Node $node, string $name): IncusGuestShell
    {
        return new IncusGuestShell($host, $node, $name);
    }

    /** Waits until cloud-init is done without errors. */
    public function awaitCloudInit(TaskVmHost $host, Node $node, string $name): void
    {
        $shell = $this->shell($host, $node, $name);
        $this->bootstrap->awaitReady($shell->agentTarget(), "VM [{$name}]", self::BootSeconds);
    }

    public function checkSmoke(IncusGuestShell $shell): void
    {
        $this->recipe->check($shell->agentTarget(), leanBoot: true);
    }

    /** Points `orbit-task-base` at the fingerprint, or creates the alias on the first image. */
    public function promote(TaskVmHost $host, Node $node, string $fingerprint): void
    {
        $this->incus->image($host, $node, ['kind' => 'promote', 'alias' => $host->imageAlias, 'fingerprint' => $fingerprint]);
    }

    /** Deletes a VM; an absent VM counts as deleted. */
    public function delete(TaskVmHost $host, Node $node, string $name): void
    {
        $this->incus->changeVm($host, $node, $name, ['kind' => 'remove_instance']);
    }

    /**
     * The instances of the host's project, as name => the fingerprint of the image each one launched.
     *
     * @return array<string, ?string>
     */
    public function instances(TaskVmHost $host, Node $node): array
    {
        return $this->incus->instanceImages($host, $node);
    }
}
