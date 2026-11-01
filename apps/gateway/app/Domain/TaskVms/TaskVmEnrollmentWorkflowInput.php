<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\NodeNetworkInput;
use App\Domain\Nodes\NodeWireGuardCommandInput;
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
    public function __construct(private TaskVmEnrollmentIntent $enrollment, private TaskVmModelKeyInputs $models,
        private SwampCommandStep $shared, private SwampSshExecutionInput $ssh, private TaskVmSettings $settings, private PiConnection $pi,
        private SystemdProcessRenderer $systemd, private TaskVmProofClubKey $club) {}

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
        $club = $this->club->capture($vm);
        $model = $this->models->capture($vm, $gateway->id, $this->shared->executionCopy(...));
        $identity = $this->ssh->identity();
        $installation = $this->enrollment->installation($vm);
        $runtime = ValidatedData::object($public['runtime']);
        $processInput = ValidatedData::object($runtime['process']);
        $process = Process::query()->findOrFail(ValidatedData::integer($processInput['process_id']));
        if (array_filter(explode("\n", $installation['environment']), static fn (string $line): bool => trim($line) !== '' && ! str_starts_with($line, '#')) !== []) {
            throw new ResourceOperationException('task_vm.enrollment_invalid', 'The Pi Process must use its guest-local credential files.', 409);
        }

        return [...$public, 'gateway_node_id' => $gateway->id, 'model_router_node_id' => $model['intent']['model_router_node_id'],
            'runtime' => [...$runtime, 'process' => [...$processInput, 'unit_name' => $this->systemd->unitName($process), 'unit_path' => $this->systemd->unitPath($process)]],
            'model_origin' => $model['intent']['model_origin'], 'model_intent' => $model['intent'],
            'ssh_identity' => $identity, 'host_ssh_execution' => $vm->hostNode instanceof Node ? $this->ssh->forNode($vm->hostNode) : null,
            'hub_ssh_execution' => $this->ssh->forNode($hub), 'network_input' => $network,
            'hub_input' => app(NodeWireGuardCommandInput::class)->capture($guest, $vm->wireguard_ip),
            'observer_digest' => $guest->agent_secret_hash, 'observer_digest_json' => json_encode($guest->agent_secret_hash, JSON_THROW_ON_ERROR),
            'bootstrap' => [...ValidatedData::object($public['bootstrap']), 'public_key' => $identity['public_key']],
            'pi_artifact' => ['path' => $this->settings->piArtifactPath, 'sha256' => $this->settings->piArtifactSha256,
                'ssh_execution' => $this->ssh->forNode($gateway)],
            'process_unit' => $installation['content'],
            'models_json' => json_encode(ValidatedData::object($public['runtime'])['models'], JSON_THROW_ON_ERROR),
            'certificate_json' => json_encode(['certificate' => ValidatedData::object($public['agent'])['certificate']], JSON_THROW_ON_ERROR),
            'runtime_json' => json_encode(['pi_sha256' => $this->settings->piArtifactSha256, 'pi_port' => $this->pi->port(), 'pi_url' => $this->pi->baseUrl($guest)], JSON_THROW_ON_ERROR),
            'execution_secrets' => [...$model['execution_secrets'], ...$club,
                'pi_token' => $this->shared->executionCopy('orbit-control-inputs', 'task-vm-pi-token-'.$vm->id, $vm->pi_token ?? throw new \LogicException('The VM has no Pi token.'))],
        ];
    }

    /** @return array<string,mixed> */
    public function capture(TaskVm $vm): array
    {
        return $this->fromCaptured($vm, $this->enrollment->capture($vm));
    }
}
