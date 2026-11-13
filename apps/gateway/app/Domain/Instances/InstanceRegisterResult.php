<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Data\Instances\InstanceData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\StepLists\InstanceStepListIntent;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeFailedStep;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Processes\ProcessLifecycleInput;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Run only admitted registration effects; the source identity comes from the agent at admission. */
final readonly class InstanceRegisterResult implements DirectOperationProjection, NativeOperationProjection
{
    private const array StepCodes = [
        'Protect the .env' => 'instance.environment_protection_failed',
        'Run Project setup steps' => 'instance.setup_step_failed',
        'Create, start Processes' => 'instance.process_create_failed',
        'Create the Route' => 'route.create_failed',
    ];

    public function __construct(private DirectOperation $direct, private RecordEventBroadcaster $broadcaster) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        $steps = [];
        if ($input['run_setup']) {
            $view = new Operation;
            $view->forceFill(['kind' => 'instance.run_steps', 'target_type' => 'instance', 'target_id' => $operation->target_id,
                'node_id' => $operation->node_id, 'input' => $input['setup']]);
            $steps = app(InstanceStepListIntent::class)->input($view)['intent']['steps'];
        }
        $copies = ValidatedData::object($input['execution_secrets'] ?? []);
        $members = [];
        foreach (ProcessInstanceOperation::members($operation) as $member) {
            $native = app(ProcessLifecycleInput::class)->forInstanceMember($operation, $member);
            $members[] = ['id' => $member['id'], 'kind' => $native['kind'], 'intent' => $native['intent']];
            if (isset($native['execution_secrets']['environment'])) {
                $copies['member_'.$member['id']] = $native['execution_secrets']['environment'];
            }
        }

        return ['intent' => ['checkout' => $input['checkout'], 'application' => $input['application'], 'user' => $input['user'],
            'ssh_execution' => $input['ssh_execution'], 'protect_environment' => $input['protect_environment'],
            'run_setup' => $input['run_setup'], 'setup_steps' => $steps, 'processes' => $members, 'route' => $input['route_create'] ?? null, 'route_secrets' => $input['execution_secrets'] ?? []],
            'execution_secrets' => $copies];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $terminal = $operation->status->terminal();
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            $native = ValidatedData::object($data['result'] ?? []);
            $step = NativeFailedStep::label($data, array_keys(self::StepCodes));
            $code = ValidatedData::nullableString($status === OperationStatus::Failed
                ? (($step === null ? null : self::StepCodes[$step] ?? null) ?? $data['error_code'] ?? 'instance.registration_failed')
                : ($data['error_code'] ?? null));
            if ($status === OperationStatus::Succeeded) {
                $this->assertCompleted($operation, $native);
            }
            $facts = RegistrationFacts::recorded(ValidatedData::object($operation->input['facts']));
            $result = $status === OperationStatus::Succeeded ? $facts : ($status->terminal() ? ['failed_step' => $step, 'error_code' => $code] : []);
            $projected = $this->direct->project($operation, [...$data, 'error_code' => $code], $result);
            if ($terminal || ! $status->terminal()) {
                return $projected;
            }
            $instance = Instance::query()->findOrFail($operation->target_id);
            foreach (ProcessInstanceOperation::members($operation) as $index => $member) {
                $memberStatus = NativeRunResult::stepStatus($native, 'Create, start Processes-'.$index);
                if (! in_array($memberStatus, ['succeeded', 'failed'], true)) {
                    continue;
                }
                $view = clone $operation;
                $view->input = [...$operation->input, 'members' => [$member]];
                app(ProcessInstanceOperation::class)->completeMembers($view, OperationStatus::from($memberStatus), $memberStatus === 'failed' ? $code : null, []);
            }
            if ($operation->input['route_id'] !== null) {
                $route = Route::query()->findOrFail(ValidatedData::integer($operation->input['route_id']));
                if (NativeRunResult::stepStatus($native, 'Create the Route') === 'succeeded') {
                    $route->update(['status' => RouteStatus::Active, 'sites_published' => true, 'failed_step' => null, 'error_code' => null]);
                } elseif (NativeRunResult::stepStatus($native, 'Create the Route') === 'failed') {
                    $route->update(['failed_step' => 'native-create', 'error_code' => $code]);
                }
            }
            if ($status === OperationStatus::Succeeded) {
                RegistrationFacts::activate($instance, $facts);
                $instance->update(['first_setup_pending' => false]);
            } else {
                $instance->update(['status' => InstanceState::Reserved, 'failed_step' => $step ?? 'registration', 'error_code' => $code]);
            }
            $this->broadcaster->broadcast(RecordEventType::InstanceUpdated, $instance->id,
                InstanceData::fromModel($instance->load(['project', 'node', 'routes.targets']))->toArray());

            return $projected;
        });
    }

    /** @param array<string,mixed> $native */
    private function assertCompleted(Operation $operation, array $native): void
    {
        $required = $operation->input['protect_environment'] ? ['Protect the .env'] : [];
        $setup = ValidatedData::object($operation->input['setup'] ?? []);
        $steps = $setup['steps'] ?? [];
        foreach (is_array($steps) ? array_keys($steps) : [] as $index) {
            $required[] = 'Run Project setup steps-'.$index;
        }
        foreach (ProcessInstanceOperation::members($operation) as $index => $_) {
            $required[] = 'Create, start Processes-'.$index;
        }
        if ($operation->input['route_id'] !== null) {
            $required[] = 'Create the Route';
        }
        foreach ($required as $name) {
            if (NativeRunResult::stepStatus($native, $name) !== 'succeeded') {
                throw new ResourceOperationException('operation.result_invalid', 'Registration has no successful result for '.$name.'.', 422);
            }
        }
    }
}
