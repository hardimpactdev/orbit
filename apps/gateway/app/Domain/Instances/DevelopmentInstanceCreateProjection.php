<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Project the original native terminal receipt once; no running phase callbacks. */
final readonly class DevelopmentInstanceCreateProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return app(NativeDevelopmentInstanceInput::class)->create($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->refresh();
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $result = ValidatedData::object($data['result'] ?? []);
            $instance = app(InstanceLifecycleState::class)->instance($operation);
            if ((ValidatedData::object($operation->input['direct_provisioning'] ?? [])['already_active'] ?? false) === true) {
                return $this->direct->project($operation, $data, $result);
            }
            if ($data['status'] === 'succeeded' && (($result['ready'] ?? false) !== true || ($result['instance_id'] ?? null) !== $instance->id || ! is_string($result['commit'] ?? null))) {
                throw new ResourceOperationException('instance.result_invalid', 'Native creation did not prove its complete terminal result.');
            }
            if (! $operation->status->terminal()) {
                // The run returns only the checked-out commit; branch, profile and environment are admitted facts.
                $input = $operation->input;
                if (is_string($result['commit'] ?? null)) {
                    $instance->update(['status' => InstanceState::SourceResolved, 'provisioning_step' => 'php-selected',
                        'branch' => ValidatedData::string(ValidatedData::object(ValidatedData::object($input['direct_provisioning'])['resolve'])['branch']),
                        'starting_commit' => ValidatedData::string($result['commit']),
                        'selected_php_version' => ValidatedData::nullableString($input['php_version'] ?? null), 'source_is_laravel' => ($input['laravel'] ?? false) === true,
                        ...(($input['seed_commit'] ?? null) !== null ? ['source_layout' => InstanceSourceLayout::Worktree] : [])]);
                }
                if ($data['status'] === 'succeeded' || in_array($result['failed_step'] ?? null, ['setup', 'processes'], true)) {
                    $instance->forceFill(['environment_owned_keys' => ValidatedData::stringList($input['environment_keys'] ?? [])])->save();
                }
                if ($data['status'] === 'succeeded') {
                    $instance->update(['status' => InstanceState::Active, 'provisioning_step' => 'active', 'first_setup_pending' => false, 'failed_step' => null, 'error_code' => null]);
                } elseif (in_array($data['status'], ['failed', 'cancelled'], true)) {
                    $instance->update(['failed_step' => $result['failed_step'] ?? 'source', 'error_code' => $data['error_code'] ?? 'operation.cancelled']);
                }
                app(ProcessInstanceOperation::class)->completeMembers($operation, OperationStatus::from(ValidatedData::string($data['status'])),
                    ValidatedData::nullableString($data['error_code'] ?? null), $result);
            }

            return $this->direct->project($operation, $data, $result);
        });
    }
}
