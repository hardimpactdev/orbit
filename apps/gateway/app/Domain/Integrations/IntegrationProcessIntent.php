<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Data\Processes\AddProcessData;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Process;

/** Integrations record their own managed Processes; the Process family owns runtime execution. */
final readonly class IntegrationProcessIntent
{
    public function __construct(private ProcessSpecification $specifications, private ProcessTargetResolver $targets, private ProcessOperationAdmission $operations) {}

    /** @return array{process: Process, input: array<string, mixed>, kind: string} */
    public function converge(Node $node, #[\SensitiveParameter] AddProcessData $data): array
    {
        $attributes = $this->specifications->attributes($data, $this->targets->forNodeAdmission($node));
        $process = Process::query()->firstOrNew(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => $data->name]);
        $created = ! $process->exists;
        $process->fill([...$attributes, 'desired_state' => $created ? DesiredProcessState::Running : $process->desired_state,
            'status' => LifecycleStatus::Provisioning, 'systemd_activation_pending' => true, 'failed_step' => null, 'error_code' => null])->save();

        return ['process' => $process, 'input' => $this->operations->inputFor($process, $created ? 'create' : 'update', $created),
            'kind' => $created ? 'process.create' : 'process.update'];
    }

    /** @return array<string, mixed> */
    public function remove(Process $process): array
    {
        return $this->operations->inputFor($process, 'destroy');
    }
}
