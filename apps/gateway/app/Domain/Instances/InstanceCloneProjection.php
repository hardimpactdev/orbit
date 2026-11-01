<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Full native input and one terminal clone projection; no phase callbacks. */
final readonly class InstanceCloneProjection implements DirectOperationProjection, NativeOperationProjection, DirectOperationWorkflowInput
{
    public function __construct(private DirectOperation $direct) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['direct_provisioning' => $operation->input['direct_provisioning']];
    }

    public function workflowInput(Operation $operation): array
    {
        $instance = Instance::query()->with(['node', 'project'])->findOrFail($operation->target_id);
        $plan = ValidatedData::object($operation->input['direct_provisioning']);
        $candidate = ValidatedData::object($plan['candidate']);
        $ssh = app(SwampSshExecutionInput::class);
        $context = $instance->clone_completed_at === null
            ? app(InstanceEnvironmentContextResolver::class)->resolveForClone($instance, true)
            : app(InstanceEnvironmentContextResolver::class)->resolve($instance, true);
        $values = app(InstanceEnvironmentStore::class)->values($instance);
        $material = json_encode(['content' => app(InstanceEnvironmentRenderer::class)->render($context, $values), 'keys' => array_keys($values)], JSON_THROW_ON_ERROR);
        $git = ['git_read' => InstanceGitHubReadTokens::reference($operation)];

        return ['intent' => [...$plan, 'ssh_execution' => $ssh->forNode($instance->node), 'environment_path' => $context->path,
            'candidate' => [...$candidate, 'ssh_execution' => $ssh->forNode(Node::query()->findOrFail(ValidatedData::integer($candidate['node_id'])))],
            'execution_references_json' => json_encode($git, JSON_THROW_ON_ERROR)],
            'execution_secrets' => [...$git, 'environment' => app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'instance-clone-environment-operation-'.$operation->id, $material)]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $terminal = $operation->refresh()->status->terminal();
            $result = Arr::only(ValidatedData::object($data['result'] ?? []), ['instance_id', 'ready', 'branch', 'commit', 'php_version', 'laravel', 'candidate_commit', 'environment_keys', 'error_code']);
            $projected = $this->direct->project($operation, $data, $result);
            if (! $terminal) {
                $instance = Instance::query()->lockForUpdate()->findOrFail($operation->target_id);
                if ($projected->status === OperationStatus::Succeeded) {
                    $instance->forceFill(['status' => InstanceState::Active, 'provisioning_step' => 'active', 'first_setup_pending' => false,
                        'branch' => $result['branch'], 'starting_commit' => $result['commit'],
                        'selected_php_version' => $result['php_version'], 'source_is_laravel' => $result['laravel'],
                        'clone_candidate_commit' => $result['candidate_commit'], 'environment_owned_keys' => $result['environment_keys'],
                        'clone_completed_at' => now(), 'failed_step' => null, 'error_code' => null])->save();
                } elseif ($projected->status->terminal()) {
                    $instance->update(['error_code' => $projected->error_code]);
                }
            }

            return $projected;
        });
    }
}
