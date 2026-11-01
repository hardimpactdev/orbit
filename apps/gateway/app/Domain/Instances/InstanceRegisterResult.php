<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Data\Instances\InstanceData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Stores the native source result; no source inspection or completion contract. */
final readonly class InstanceRegisterResult implements DirectOperationProjection, NativeOperationProjection
{
    public function __construct(private DirectOperation $direct, private RecordEventBroadcaster $broadcaster) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['intent' => [...$operation->input, 'kind' => $operation->kind, 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id))], 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $terminal = $operation->refresh()->status->terminal();
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            $result = Arr::only(ValidatedData::object($data['result'] ?? []), [
                'registered', 'branch', 'commit', 'detached', 'digest', 'common', 'default_branch', 'inferred_slug', 'php_version', 'laravel', 'error_code',
            ]);
            $projected = $this->direct->project($operation, $data, $result);
            if (! $terminal && $status === OperationStatus::Succeeded) {
                $input = $operation->input;
                $instance = Instance::query()->findOrFail($operation->target_id);
                $instance->update([
                    'status' => InstanceState::Active, 'provisioning_step' => 'active',
                    'branch' => ValidatedData::nullableString($result['branch'] ?? null),
                    'branch_override' => ValidatedData::nullableString($result['branch'] ?? null),
                    'starting_commit' => ValidatedData::string($result['commit']),
                    'selected_php_version' => ValidatedData::nullableString($result['php_version']),
                    'source_is_laravel' => $result['laravel'] === true,
                    'registration_repository_url' => $input['repository'], 'registration_repository_identity' => $input['repository_identity'],
                    'registration_source_digest' => ValidatedData::string($result['digest']),
                    'registration_detached' => $result['detached'] === true,
                    'registration_default_branch' => ValidatedData::nullableString($result['default_branch'] ?? null),
                    'registration_inferred_slug' => ValidatedData::string($result['inferred_slug']),
                    'registration_common_repository_path' => ValidatedData::string($result['common']), 'registration_worktree_paths' => [$input['checkout']],
                    'registration_relocation_state' => 'relocated', 'registration_completed_at' => now(), 'failed_step' => null, 'error_code' => null,
                ]);
                $this->broadcaster->broadcast(RecordEventType::InstanceUpdated, $instance->id,
                    InstanceData::fromModel($instance->load(['project', 'node', 'routes.targets']))->toArray());
            }

            return $projected;
        });
    }
}
