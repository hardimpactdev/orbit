<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Domain\TaskVms\VmObservation;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\TaskVm;

/**
 * Runs task VMs as UpCloud servers, the overflow of beast and shark. Each server clones its recorded verified
 * `orbit-task-base-<time>` template ({@see UpCloudTaskVmImageBuilder}), so it boots the same base image contract
 * as an Incus VM. It has no host Node: the Gateway reaches it over SSH at its public IPv4 address with the host key
 * that the Gateway generated for it ({@see UpCloudGuest}), and enrollment needs no jump Node.
 *
 * Before each create it counts the account's task VM servers, so a server that a row lost still counts against
 * `max_vms`. A create whose answer was lost is found again by its label, and never sent twice.
 */
final readonly class UpCloudTaskVmProvider implements TaskVmProvider
{
    private const int StartSeconds = 240;

    public function __construct(
        private UpCloudServers $servers,
        private UpCloudGuest $guest,
        private SshKeyProvider $keys,
        private GuestBootstrap $bootstrap,
    ) {}

    public function create(TaskVm $vm, string $gatewayPublicKey): void
    {
        $this->allocate($vm, $gatewayPublicKey);
        $this->start($vm);
    }

    public function allocate(TaskVm $vm, string $gatewayPublicKey): void
    {
        $server = $this->server($vm);
        if ($server === null) {
            $upcloud = $this->servers->upcloud();
            $servers = count($this->servers->labelled(TaskVmUpCloud::Label));
            if ($servers >= $upcloud->maxVms) {
                throw new TaskVmException('task_vm.upcloud_budget_full', "UpCloud already runs {$servers} task VM servers, the limit of {$upcloud->maxVms}.", 409);
            }
            $template = $vm->image_fingerprint ?? $this->servers->currentTemplate($vm->recipe_identity ?? app(RecipeImageBuilds::class)->currentRecipe()->identity)
                ?? throw new TaskVmException('task_vm.base_image_missing', 'UpCloud has no verified task VM template [orbit-task-base-<time>]. Build it with task-vms:build-upcloud-image.');
            $hostKey = $this->guest->generateHostKey($vm->name);
            // The key is stored before the request, so a server whose answer is lost still has its pinned key.
            $vm->update(['image_fingerprint' => $template, 'ssh_host_key' => $hostKey['public'], 'provider_id' => null]);
            $uuid = $this->servers->create($vm->name, TaskVmUpCloud::Label, (string) $vm->id, $template, TaskVmCloudInit::withHostKey(new TaskVmCloudInit()->render($gatewayPublicKey), $hostKey), $this->keys->publicKey());
            $vm->update(['provider_id' => $uuid]);

            return;
        }
    }

    public function observe(TaskVm $vm): ?VmObservation
    {
        $server = $this->server($vm);

        return $server === null ? null : new VmObservation($server->state === 'started', $server->address, settled: in_array($server->state, ['started', 'stopped'], true));
    }

    /** The key the Gateway generated and gave the server: the server proves it holds it on every SSH connection. */
    public function sshHostFingerprint(TaskVm $vm): string
    {
        return UpCloudGuest::fingerprint($this->hostKey($vm));
    }

    public function bootstrapReady(TaskVm $vm): bool
    {
        $address = $this->observe($vm)?->address;
        if ($address === null) {
            return false;
        }
        $guest = AgentGuest::ssh($vm->name, 'x86_64', $this->guest->connection($address, $this->hostKey($vm)));

        return $this->bootstrap->ready($guest, "task VM [{$vm->name}]");
    }

    public function start(TaskVm $vm): void
    {
        $this->startServer($vm, $this->server($vm) ?? throw new TaskVmException(TaskVmError::VmMissing, "Task VM [{$vm->name}] is gone from UpCloud.", 502));
    }

    /** A clean shutdown first, then a hard stop once the guest has not stopped within 120 seconds. */
    public function stop(TaskVm $vm): void
    {
        $server = $this->server($vm);
        if ($server === null || $server->state === 'stopped') {
            return;
        }
        $uuid = $server->uuid;
        if ($server->state === 'started') {
            $this->servers->stop($uuid, 120);
        }
        try {
            $this->servers->await($vm->name, TaskVmUpCloud::Label, (string) $vm->id, $uuid, 'stopped', 150);
        } catch (TaskVmException $exception) {
            if ($exception->errorCode !== 'task_vm.upcloud_timeout') {
                throw $exception;
            }
            $this->servers->stop($uuid, null);
            $this->servers->await($vm->name, TaskVmUpCloud::Label, (string) $vm->id, $uuid, 'stopped', 120);
        }
    }

    /** Deletes the server and its disk. An absent server counts as deleted. */
    /** Submits one fixed power request; Swamp owns waits and hard-stop escalation. */
    public function requestStop(TaskVm $vm, bool $hard = false): void
    {
        $server = $this->server($vm);
        if ($server !== null && ($server->state === 'started' || ($hard && $server->state !== 'stopped'))) {
            $this->servers->stop($server->uuid, $hard ? null : 120);
        }
    }

    /** Deletes the server and its disk. An absent server counts as deleted. */
    /** Submits one fixed start request; the workflow owns observations and waits. */
    public function requestStart(TaskVm $vm): void
    {
        $server = $this->server($vm) ?? throw new TaskVmException(TaskVmError::VmMissing, "Task VM [{$vm->name}] is gone from UpCloud.", 502);
        if ($server->state === 'stopped') {
            $this->servers->start($server->uuid);
        }
    }

    public function destroy(TaskVm $vm): void
    {
        $this->servers->delete($vm->name, TaskVmUpCloud::Label, (string) $vm->id, $vm->provider_id);
    }

    private function server(TaskVm $vm): ?UpCloudServer
    {
        $server = $this->servers->find($vm->name, TaskVmUpCloud::Label, (string) $vm->id, $vm->provider_id);
        if ($server instanceof UpCloudServer && $vm->provider_id === null) {
            $vm->update(['provider_id' => $server->uuid]);
        }

        return $server;
    }

    private function startServer(TaskVm $vm, UpCloudServer $server): void
    {
        if ($server->state === 'stopped') {
            $this->servers->start($server->uuid);
        }
        if ($server->state !== 'started') {
            $this->servers->await($vm->name, TaskVmUpCloud::Label, (string) $vm->id, $server->uuid, 'started', self::StartSeconds);
        }
    }

    private function hostKey(TaskVm $vm): string
    {
        return $vm->ssh_host_key ?? throw new TaskVmException('task_vm.host_key_failed', "Task VM [{$vm->name}] has no pinned host key.", 500);
    }
}
