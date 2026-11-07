<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\NodeNetworkConvergeInput;
use App\Domain\Nodes\NodeNetworkInput;
use App\Domain\Nodes\NodeWireGuardHubInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Models\Node;
use App\Models\Process;
use App\Models\TaskVm;
use App\Support\ValidatedData;

/** The complete public enrollment description; credentials have names only. */
final readonly class TaskVmEnrollmentWorkflowInput
{
    public function __construct(private TaskVmEnrollmentIntent $enrollment, private TaskVmModelRoute $route,
        private SwampCommandStep $shared, private SwampSshExecutionInput $ssh, private TaskVmSettings $settings, private PiConnection $pi,
        private SystemdProcessRenderer $systemd) {}

    /** @param array<string,mixed> $public
     * @return array<string,mixed>
     */
    public function fromCaptured(TaskVm $vm, array $public): array
    {
        $guest = $vm->node ?? throw new ResourceOperationException('task_vm.enrollment_invalid', 'Enrollment requires its reserved guest.', 409);
        $gateway = Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($q) => $q
            ->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
        $network = app(NodeNetworkInput::class)->capture($guest);
        $hub = Node::query()->findOrFail(ValidatedData::integer($network['hub_id']));
        $identity = $this->ssh->identity();
        // The guest's agent secret, made here as for node:add: the guest writes the vault copy as is, and the Gateway knows its hash.
        $agentSecret = bin2hex(random_bytes(32));
        $installation = $this->enrollment->installation($vm);
        $runtime = ValidatedData::object($public['runtime']);
        $processInput = ValidatedData::object($runtime['process']);
        $process = Process::query()->findOrFail(ValidatedData::integer($processInput['process_id']));
        if (array_filter(explode("\n", $installation['environment']), static fn (string $line): bool => trim($line) !== '' && ! str_starts_with($line, '#')) !== []) {
            throw new ResourceOperationException('task_vm.enrollment_invalid', 'The Pi Process must use its guest-local credential files.', 409);
        }

        return [...$public, 'gateway_node_id' => $gateway->id,
            'runtime' => [...$runtime, 'process' => [...$processInput, 'unit_name' => $this->systemd->unitName($process), 'unit_path' => $this->systemd->unitPath($process)]],
            'ssh_identity' => $identity, 'host_ssh_execution' => $vm->hostNode instanceof Node ? $this->ssh->forNode($vm->hostNode) : null,
            'hub_ssh_execution' => $this->ssh->forNode($hub), 'network_input' => $network,
            // The guest's peer file on the hub, with a mark for the key the guest prepares, and the guest's own files.
            'hub' => NodeWireGuardHubInput::joining($hub, $guest->id, $guest->name, ValidatedData::string($vm->wireguard_ip)),
            'peer_files' => NodeNetworkConvergeInput::peerFiles(ValidatedData::object($network['peer'])),
            'observer_digest' => hash('sha256', $agentSecret),
            'bootstrap' => [...ValidatedData::object($public['bootstrap']), 'public_key' => $identity['public_key']],
            'pi_artifact' => ['path' => $this->settings->piArtifactPath, 'sha256' => $this->settings->piArtifactSha256,
                'ssh_execution' => $this->ssh->forNode($gateway)],
            'process_unit' => $installation['content'],
            'certificate' => ValidatedData::string(ValidatedData::object($public['agent'])['certificate']),
            'pi_url' => $this->pi->baseUrl($guest),
            'execution_secrets' => [
                'agent_secret' => $this->shared->executionCopy('orbit-control-inputs', 'agent-secret-node-'.$guest->id, $agentSecret),
                // The whole Pi model file is one vault value: Swamp writes a secret as file content, never spliced into text.
                'pi_models' => $this->shared->executionCopy('orbit-control-inputs', 'task-vm-pi-models-'.$vm->id, $this->piModels($runtime)),
                'pi_token' => $this->shared->executionCopy('orbit-control-inputs', 'task-vm-pi-token-'.$vm->id, $vm->pi_token ?? throw new \LogicException('The VM has no Pi token.'))],
        ];
    }

    /** @return array<string,mixed> */
    public function capture(TaskVm $vm): array
    {
        return $this->fromCaptured($vm, $this->enrollment->capture($vm));
    }

    /** @param  array<string,mixed>  $runtime */
    private function piModels(array $runtime): string
    {
        return TaskVmSlotRuns::piModelsFile(ValidatedData::string($runtime['origin']), ValidatedData::stringList($runtime['models']), $this->route->key());
    }
}
