<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmGuestCommand;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\VmObservation;
use App\Infrastructure\Processes\CommandResult;
use App\Models\TaskVm;

/** The Gateway's placement and resource intent for task VMs on a managed Incus host. */
final readonly class IncusTaskVmProvider implements TaskVmProvider
{
    private const string FingerprintPattern = '/\A256 (SHA256:[A-Za-z0-9+\/]{43}) .* \(ED25519\)\n?\z/D';

    public function __construct(
        private IncusHost $incus,
        private TaskVmSettings $settings,
    ) {}

    public function create(TaskVm $vm, string $gatewayPublicKey): void
    {
        $host = $this->settings->host((int) $vm->host_node_id);
        $resources = $vm->size->resources();
        $this->incus->changeVm($host, $vm->incusHostNode(), $vm->name, [
            'kind' => 'create', 'image' => $vm->image_fingerprint ?? $host->imageAlias,
            'expected_fingerprint' => $vm->recipe_identity === null ? null : $vm->image_fingerprint,
            'base_alias' => $host->imageAlias, 'gateway_public_key' => $gatewayPublicKey,
            'cpus' => $resources->cpusOn($host), 'memory' => $resources->memoryOn($host), 'disk' => $resources->diskOn($host),
            'network' => $host->network, 'pool' => $host->pool, 'topology' => $resources->testNodes > 0,
        ]);
    }

    /** @phpstan-impure */
    public function observe(TaskVm $vm): ?VmObservation
    {
        return $this->incus->vmState($this->settings->host((int) $vm->host_node_id), $vm->incusHostNode(), $vm->name);
    }

    public function sshHostFingerprint(TaskVm $vm): string
    {
        $result = $this->incus($vm, ['exec', '--', $vm->name, 'ssh-keygen', '-l', '-f', '/etc/ssh/ssh_host_ed25519_key.pub']);
        if (! $result->succeeded()) {
            throw $this->failed($vm, 'exec ssh-keygen', $result);
        }
        if (preg_match(self::FingerprintPattern, $result->stdout, $match) !== 1) {
            throw $this->invalid($vm, 'the host key fingerprint is not an ed25519 SHA256 fingerprint');
        }

        return $match[1];
    }

    /** Runs the command as `root` with `incus exec`. Until the guest agent runs, `incus exec` fails and prints nothing on stdout. */
    public function exec(TaskVm $vm, TaskVmGuestCommand $command): CommandResult
    {
        return $this->incus->run($this->settings->host((int) $vm->host_node_id), $vm->incusHostNode(), ['exec', '--', $vm->name, ...$command->argv()], timeout: $command->timeoutSeconds());
    }

    public function start(TaskVm $vm): void
    {
        $this->change($vm, ['kind' => 'start']);
    }

    public function stop(TaskVm $vm): void
    {
        $this->change($vm, ['kind' => 'stop']);
    }

    public function destroy(TaskVm $vm): void
    {
        $this->change($vm, ['kind' => 'delete', 'pool' => $this->settings->host((int) $vm->host_node_id)->pool]);
    }

    /** @param array<string, mixed> $operation */
    private function change(TaskVm $vm, array $operation): void
    {
        $this->incus->changeVm($this->settings->host((int) $vm->host_node_id), $vm->incusHostNode(), $vm->name, $operation);
    }

    /** @param  list<string>  $arguments */
    private function incus(TaskVm $vm, array $arguments): CommandResult
    {
        return $this->incus->run($this->settings->host((int) $vm->host_node_id), $vm->incusHostNode(), $arguments);
    }

    private function invalid(TaskVm $vm, string $reason): TaskVmException
    {
        return IncusHost::invalid("task VM [{$vm->name}]", $reason);
    }

    private function failed(TaskVm $vm, string $operation, CommandResult $result): TaskVmException
    {
        return IncusHost::failed("task VM [{$vm->name}]", $operation, $result);
    }
}
