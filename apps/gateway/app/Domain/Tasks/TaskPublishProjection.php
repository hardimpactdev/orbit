<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\Environment\DevelopmentCreateEnvironment;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Projects native publication metadata onto the board. No GitHub calls or execution. */
final readonly class TaskPublishProjection implements DirectOperationProjection, DirectOperationWorkflowInput
{
    public function input(Operation $operation): array
    {
        return ['intent' => array_diff_key($operation->input, ['execution_secrets' => true]),
            'execution_secrets' => $operation->input['execution_secrets']];
    }

    /**
     * A task copy's publish also gets the .env Orbit renders from the Instance's stored values, as a vault copy: the worker checks
     * the candidate against it as well as against the copy's own file, which the agent can change (docs/reference/tasks.md#task-copy).
     */
    public function workflowInput(Operation $operation): array
    {
        $input = $this->input($operation);
        if (! isset($operation->input['workspace'])) {
            return $input;
        }
        $instance = Instance::query()->findOrFail(ValidatedData::integer($operation->input['instance_id']));
        $environment = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs',
            'task-publish-environment-'.$operation->id, app(DevelopmentCreateEnvironment::class)->content($instance));

        return [...$input, 'execution_secrets' => [...ValidatedData::object($input['execution_secrets']), 'environment' => $environment]];
    }

    /** Only the check's own refusal line names content; any other failure of the check refuses as unchecked. */
    private static function contentCode(mixed $detail): string
    {
        return is_string($detail) && preg_match('/^tasks\.publish_secret_content: /m', $detail) === 1
            ? 'tasks.publish_secret_content' : 'tasks.publish_secret_unchecked';
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $native = ValidatedData::object($data['result'] ?? []);
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            if ($status === OperationStatus::Succeeded) {
                $pull = isset($native['pull_receipt']) ? ValidatedData::object(json_decode(ValidatedData::string($native['pull_receipt']), true, flags: JSON_THROW_ON_ERROR)) : [];
                $result = ['branch' => $operation->input['branch'], 'commit' => $operation->input['commit'],
                    'pull_request_number' => $pull['pull_request_number'] ?? null, 'pull_request_url' => $pull['pull_request_url'] ?? null];
            } elseif (in_array($status, [OperationStatus::Failed, OperationStatus::Interrupted], true)) {
                $failure = ValidatedData::object($data['failure'] ?? []);
                $detail = $failure['detail'] ?? null;
                $step = $failure['failed_step'] ?? null;
                $code = in_array($detail, ['tasks.head_moved', 'tasks.not_fast_forward', 'tasks.workflows_permission', 'tasks.push_failed', 'tasks.publish_failed', 'tasks.repository_execution_unknown'], true)
                    ? $detail : ($step === 'Check the environment file' ? 'tasks.publish_secret_path' : ($step === 'Check the .env content' ? self::contentCode($detail) : ($step === 'Push the branch' ? 'tasks.push_failed' : (in_array($step, ['Init the bare repo', 'Import the bundle', 'Read bundle contents'], true) ? 'tasks.repository_execution_unknown' : 'tasks.publish_failed'))));
                $data['error_code'] = $code;
                $result = ['error_code' => $code];
            } else {
                $result = [];
            }
            $data['result'] = $result;
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $projected->status->value === 'succeeded' && isset($result['pull_request_url'])) {
                Task::topLevel()->whereKey($operation->target_id)->update([
                    'pr_url' => $result['pull_request_url'], 'watched_pr_url' => $result['pull_request_url'],
                    'watched_pr_number' => $result['pull_request_number'], 'watched_pr_state' => 'open',
                ]);
            }

            if (! $terminal && isset($result['pull_request_url'])) {
                TaskVmReentry::release($projected);
            }
            app(TaskMaintenanceContinuation::class)->apply($projected);

            return $projected;
        });
    }
}
