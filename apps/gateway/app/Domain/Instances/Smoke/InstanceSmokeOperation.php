<?php

declare(strict_types=1);

namespace App\Domain\Instances\Smoke;

use App\Actions\Instances\StartInstanceSmokeAction;
use App\Data\Instances\InstanceSmokeRequestData;
use App\Data\Instances\InstanceSmokeResultData;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationStepPlan;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\OperationWaitingKind;
use App\Domain\Operations\OperationWaitingStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GitHub\GitHubActionsReader;
use App\Infrastructure\Instances\GitHubInstanceSmoke;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class InstanceSmokeOperation implements OperationWaitingKind, OperationWaitingStep
{
    public function __construct(private GitHubInstanceSmoke $github, private GitHubActionsReader $actions, private StartInstanceSmokeAction $admission, private OperationStore $store) {}

    public function run(Operation $operation, OperationRun $run): OperationOutcome
    {
        $run->plan([new OperationStepPlan('dispatch', true), new OperationStepPlan('evidence', true)]);
        $run->step('dispatch', function () use ($operation, $run): void {
            if (isset($operation->state['intent'])) {
                return;
            }
            $request = InstanceSmokeRequestData::from($operation->input);
            $repository = $this->repository($operation);
            $run->remember('workflow', fn (): array => $this->github->resolve($repository, $request));
            $workflow = $this->workflow($operation);
            $token = $this->actions->dispatchToken($repository);
            $instance = Instance::query()->findOrFail($operation->target_id);
            $this->admission->assertSelection($instance, $request);
            if ($instance->project->repository_url !== $operation->input['repository_url'] || ! $instance->routes()->where('routes.id', $operation->input['route_id'])->exists()) {
                throw new ResourceOperationException('instance.smoke_invalid', 'The admitted repository or Route changed.');
            }
            $run->remember('intent', static fn (): string => now()->toIso8601String());
            try {
                $this->github->send($repository, $workflow['id'], $request, $token);
            } catch (Throwable) {
                Log::warning('Production smoke send is uncertain.', ['operation' => $operation->id, 'code' => 'instance.smoke_send_uncertain']);
            }
        });
        $result = $run->step('evidence', fn (): mixed => $this->read($run));

        return OperationOutcome::succeeded(is_array($result) ? $result : []);
    }

    public function waitingStep(Operation $operation, string $name): OperationWaitingStep
    {
        return $this;
    }

    public function read(OperationRun $run): mixed
    {
        $operation = $run->operation();
        $request = InstanceSmokeRequestData::from($operation->input);
        $workflow = $this->workflow($operation);
        $result = InstanceSmokeResultData::from([...$request->toArray(), 'workflow_sha' => $workflow['sha']]);
        try {
            $result = $this->github->observe($this->repository($operation), $request, $workflow, $this->intent($operation));
        } catch (Throwable) {
            Log::warning('Production smoke evidence is unavailable.', ['operation' => $operation->id, 'code' => 'instance.smoke_evidence_unavailable']);
        }
        if ($result->state === 'pending' && Carbon::parse($this->intent($operation))->addMinutes(30)->isPast()) {
            $result->state = 'unknown';
        }
        $this->store->change($operation, ['state' => [...($operation->state ?? []), 'smoke' => $result->toArray()]]);
        if ($result->state === 'pending') {
            $run->wait(10);
        }

        return $result->toArray();
    }

    public function stop(OperationRun $run): void {}

    public function abandon(Operation $operation, OperationOutcome $failure, ?string $failedStep): OperationOutcome
    {
        $result = InstanceSmokeResultData::from([...$operation->input, ...(is_array($operation->state['smoke'] ?? null) ? $operation->state['smoke'] : []), 'state' => 'unknown']);

        return $failure->status->value === 'cancelled' ? OperationOutcome::cancelled($result->toArray()) : OperationOutcome::failed($failure->errorCode ?? 'instance.smoke_unknown', $result->toArray());
    }

    private function repository(Operation $operation): GitHubRepository
    {
        $origin = $operation->input['repository_url'] ?? null;

        return (is_string($origin) ? GitHubRepository::fromOrigin($origin) : null) ?? throw new ResourceOperationException('instance.smoke_invalid', 'The admitted repository is invalid.');
    }

    private function intent(Operation $operation): string
    {
        $intent = $operation->state['intent'] ?? null;
        if (! is_string($intent)) {
            throw new ResourceOperationException('instance.smoke_invalid', 'Dispatch intent is missing.');
        }

        return $intent;
    }

    /** @return array{id: int, sha: string} */
    private function workflow(Operation $operation): array
    {
        $value = $operation->state['workflow'] ?? null;
        if (! is_array($value) || ! is_int($value['id'] ?? null) || ! is_string($value['sha'] ?? null)) {
            throw new ResourceOperationException('instance.smoke_invalid', 'Workflow identity is missing.');
        }

        return ['id' => $value['id'], 'sha' => $value['sha']];
    }
}
