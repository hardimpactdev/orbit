<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class AnalyticsUpdateProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private DockerProcessRenderer $docker,
        private ProcessTargetResolver $targets, private AnalyticsRoleSettingsRepository $settings) {}

    public function input(Operation $operation): array
    {
        return [...$operation->input, ...ValidatedData::object($operation->state['runtime_plan'] ?? [])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = ValidatedData::object($data['result'] ?? []);
        $plan = ValidatedData::object($operation->state['runtime_plan'] ?? []);
        if ($status === OperationStatus::Succeeded) {
            $expected = ['ready' => true, 'version' => $operation->input['version'], 'previous_version' => $plan['previous_version'] ?? null];
            ksort($expected);
            ksort($result);
            if ($result !== $expected || $plan === []) {
                throw new ResourceOperationException('operation.result_invalid', 'The update result must match the admitted runtime.', 422);
            }
        } elseif ($status === OperationStatus::Failed) {
            if (array_diff(array_keys($result), ['error_code', 'rolled_back']) !== [] || ! is_bool($result['rolled_back'] ?? null)
                || ($result['error_code'] ?? null) !== ($data['error_code'] ?? null) || ! is_string($data['error_code'] ?? null)) {
                throw new ResourceOperationException('operation.result_invalid', 'The update failure contains only its code and rollback observation.', 422);
            }
        } elseif ($result !== []) {
            throw new ResourceOperationException('operation.result_invalid', 'Only terminal update observations carry results.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $status, $result): Operation {
            $operation->refresh();
            $terminal = $operation->status->terminal();
            if (! $terminal && $status === OperationStatus::Succeeded) {
                $plan = ValidatedData::object($operation->state['runtime_plan'] ?? []);
                $process = $this->process($operation);
                $spec = $this->docker->specHash($process, $this->targets->forInstallation($process));
                $before = ValidatedData::object($plan['before'] ?? []);
                $after = ValidatedData::object($plan['after'] ?? []);
                if ($process->desired_state !== DesiredProcessState::Running || ! in_array($spec, [$before['spec'] ?? null, $after['spec'] ?? null], true)) {
                    throw new ResourceOperationException('analytics.runtime_changed', 'The Plausible definition changed after this update was admitted.', 409);
                }
            }
            $projected = $this->direct->project($operation, $data, $result, 'analytics-update');
            if ($terminal) {
                return $projected;
            }
            if ($status === OperationStatus::Running && ! isset($projected->state['runtime_plan'])) {
                $projected->update(['state' => [...($projected->state ?? []), 'runtime_plan' => $this->plan($projected)]]);
            }
            if ($status === OperationStatus::Succeeded) {
                $process = $this->process($projected);
                $process->update(['runtime_config' => [...$process->runtime_config, 'image' => PlausibleProcess::IMAGE.':v'.ValidatedData::string($projected->input['version'])],
                    'status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
                $this->settings->storeVersion(Node::query()->findOrFail($projected->node_id), ValidatedData::string($projected->input['version']));
            }
            if ($status === OperationStatus::Failed && $result['rolled_back'] === false) {
                $this->process($projected)->update(['status' => LifecycleStatus::Failed, 'failed_step' => 'analytics-update', 'error_code' => $result['error_code']]);
            }

            return $projected;
        });
    }

    /** @return array<string, mixed> */
    private function plan(Operation $operation): array
    {
        $process = $this->process($operation);
        if ($process->desired_state !== DesiredProcessState::Running) {
            throw new ResourceOperationException('analytics.runtime_stopped', 'Start the Plausible Process before updating its version.', 409);
        }
        $target = $this->targets->forInstallation($process);
        $environment = ValidatedData::stringMap($process->runtime_config['environment'] ?? []);
        ksort($environment);
        $before = $this->docker->containerInput($process, $target);
        unset($before['environment']);
        $before += ['process_id' => $process->id, 'container' => $this->docker->containerName($process),
            'desired' => 'running', 'spec' => $this->docker->specHash($process, $target)];
        $changed = clone $process;
        $changed->runtime_config = [...$process->runtime_config, 'image' => PlausibleProcess::IMAGE.':v'.ValidatedData::string($operation->input['version'])];

        return ['before' => $before, 'after' => [...$before, 'image' => $changed->runtime_config['image'], 'spec' => $this->docker->specHash($changed, $target)],
            'environment_sha256' => hash('sha256', json_encode($environment, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'health_url' => 'http://'.$target->node->wireguard_ip.':'.PlausibleProcess::PORT.'/api/health',
            'previous_version' => $this->settings->version($target->node)];
    }

    private function process(Operation $operation): Process
    {
        $process = Process::query()->findOrFail(ValidatedData::integer($operation->input['process_id']));
        if ($process->owner_type !== Node::class || $process->owner_id !== $operation->node_id || $process->name !== PlausibleProcess::NAME || $process->runtime !== ProcessRuntime::Docker) {
            throw new ResourceOperationException('analytics.runtime_changed', 'The admitted Plausible Process identity changed.', 409);
        }

        return $process;
    }
}
