<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Data\Instances\InstancePublishInput;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Operations\OperationKind;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationStepPlan;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GitHub\InstancePublisher;
use App\Models\Instance;
use App\Models\Operation;

final readonly class InstancePublishOperation implements OperationKind
{
    public function __construct(private InstancePublisher $publisher, private InstanceEnvironmentOperationLock $locks) {}

    public function run(Operation $operation, OperationRun $run): OperationOutcome
    {
        $instance = Instance::query()->find($operation->target_id);
        if (! $instance instanceof Instance) {
            return OperationOutcome::failed('operation.target_missing');
        }
        $input = InstancePublishInput::from($operation->input);
        $run->plan([new OperationStepPlan('push', true), ...($input->pull_request === null ? [] : [new OperationStepPlan('pull_request', true)])]);
        try {
            return $this->locks->run([$instance->id], function () use ($instance, $input, $run): OperationOutcome {
                $run->step('push', fn () => $this->publisher->push($instance, $input));
                $pr = $input->pull_request === null ? null : $run->step('pull_request', function () use ($instance, $input): array {
                    $opened = $this->publisher->pullRequest($instance, $input);

                    return ['number' => $opened->number, 'url' => $opened->url];
                });

                $pr = is_array($pr) ? $pr : [];

                return OperationOutcome::succeeded(['branch' => $input->branch, 'commit' => $input->commit, 'pull_request_number' => $pr['number'] ?? null, 'pull_request_url' => $pr['url'] ?? null]);
            });
        } catch (RuntimeConvergenceException|ResourceOperationException $exception) {
            return OperationOutcome::failed($exception->errorCode);
        } catch (GitHubApiException) {
            return OperationOutcome::failed('instance_publish.github_failed');
        }
    }

    public function abandon(Operation $operation, OperationOutcome $failure, ?string $failedStep): OperationOutcome
    {
        return $failure;
    }
}
