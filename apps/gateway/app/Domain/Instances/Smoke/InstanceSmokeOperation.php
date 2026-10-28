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
use App\Infrastructure\GitHub\GitHubActionsUnavailable;
use App\Infrastructure\Instances\GitHubInstanceSmoke;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class InstanceSmokeOperation implements OperationWaitingKind, OperationWaitingStep
{
    public function __construct(
        private GitHubInstanceSmoke $github,
        private GitHubActionsReader $actions,
        private StartInstanceSmokeAction $admission,
        private OperationStore $store,
    ) {}

    public function run(Operation $operation, OperationRun $run): OperationOutcome
    {
        $run->plan([new OperationStepPlan('dispatch', true), new OperationStepPlan('evidence', true)]);
        $run->step('dispatch', fn () => $this->dispatch($run));
        $run->step('evidence', fn () => $this->read($run));
        $state = $this->result($operation)->state;

        return $state === 'unknown'
            ? OperationOutcome::failed($operation->error_code ?? 'instance.smoke_evidence_invalid', ['state' => $state])
            : OperationOutcome::succeeded(['state' => $state]);
    }

    private function dispatch(OperationRun $run): void
    {
        $operation = $run->operation();
        if (isset($operation->state['intent'])) {
            return;
        }
        $attempt = ValidatedData::integer($operation->state['prepare_attempts'] ?? 0) + 1;
        $this->store->change($operation, ['state' => [...($operation->state ?? []), 'prepare_attempts' => $attempt]]);
        try {
            if ($this->expired($operation)) {
                throw new ResourceOperationException('instance.smoke_observation_expired', 'Smoke observation expired.');
            }
            $request = InstanceSmokeRequestData::from($operation->input);
            $repository = $this->repository($operation);
            $workflow = $this->github->resolve($repository, $request);
            $this->store->change($operation, ['state' => [...($operation->state ?? []), 'workflow' => $workflow]]);
            $token = $this->actions->dispatchToken($repository);
            $instance = Instance::query()->findOrFail($operation->target_id);
            $this->admission->assertSelection($instance, $request);
            if ($instance->project->repository_url !== $operation->input['repository_url']
                || ! $instance->routes()->where('routes.id', $operation->input['route_id'])->exists()) {
                throw new ResourceOperationException('instance.smoke_invalid', 'The admitted repository or Route changed.');
            }
        } catch (Throwable $exception) {
            if ($exception instanceof ResourceOperationException && str_starts_with($exception->errorCode, 'instance.smoke_')) {
                throw $exception;
            }
            if ($exception instanceof GitHubActionsUnavailable && $exception->status !== null && ! $exception->retryable()) {
                throw new ResourceOperationException('instance.smoke_invalid', 'GitHub refused the workflow or repository.');
            }
            if ($attempt < 3) {
                $run->wait(10);
            }
            throw new ResourceOperationException('instance.smoke_prepare_unavailable', 'Smoke preparation could not be read.', 503);
        }
        $run->remember('intent', static fn (): string => now()->toIso8601String());
        try {
            $this->github->send($repository, $workflow['id'], $request, $token);
        } catch (GitHubActionsUnavailable $exception) {
            if ($exception->status !== null && ! $exception->retryable()) {
                $this->store->change($operation, ['state' => [...($operation->state ?? []), 'dispatch_refused' => true]]);
            } else {
                Log::warning('Production smoke send is uncertain.', ['operation' => $operation->id]);
            }
        } catch (Throwable) {
            Log::warning('Production smoke send is uncertain.', ['operation' => $operation->id]);
        }
    }

    public function waitingStep(Operation $operation, string $name): OperationWaitingStep
    {
        return $this;
    }

    public function read(OperationRun $run): mixed
    {
        $operation = $run->operation();
        if ($operation->current_step === 'dispatch') {
            $this->dispatch($run);

            return null;
        }
        $result = $this->result($operation);
        if ($result->state !== 'pending') {
            return null;
        }
        $workflow = $this->workflow($operation);
        $result->workflowSha = $workflow['sha'];
        $observation = new SmokeObservation($result);
        if ($this->expired($operation)) {
            $result->state = 'unknown';
            $observation = new SmokeObservation($result, 'instance.smoke_observation_expired');
        } elseif ($operation->state['dispatch_refused'] ?? false) {
            $result->state = 'unknown';
            $observation = new SmokeObservation($result, 'instance.smoke_dispatch_refused');
        } else {
            try {
                $observation = $this->github->observe($this->repository($operation),
                    InstanceSmokeRequestData::from($operation->input), $workflow,
                    $this->intent($operation));
            } catch (Throwable $exception) {
                if (! $exception instanceof GitHubActionsUnavailable || ! $exception->retryable()) {
                    $result->state = 'unknown';
                }
                $observation = new SmokeObservation($result, $result->state === 'unknown'
                    ? 'instance.smoke_evidence_invalid' : 'instance.smoke_github_unavailable');
                Log::warning('Production smoke evidence is unavailable.', ['operation' => $operation->id]);
            }
        }
        $result = $observation->result;
        $code = $observation->errorCode;
        if ($result->state === 'pending' && $this->expired($operation)) {
            $result->state = 'unknown';
            $code = 'instance.smoke_observation_expired';
        }
        $this->store->change($operation, [
            'state' => [...($operation->state ?? []), 'smoke' => $result->toArray()],
            'error_code' => $code,
        ]);
        if ($result->state === 'pending') {
            $run->wait(10);
        }

        return null;
    }

    public function stop(OperationRun $run): void {}

    public function abandon(Operation $operation, OperationOutcome $failure, ?string $failedStep): OperationOutcome
    {
        $result = $this->result($operation);
        $result->state = 'unknown';
        $this->store->change($operation, ['state' => [...($operation->state ?? []), 'smoke' => $result->toArray()]]);

        return $failure->status->value === 'cancelled' ? OperationOutcome::cancelled(['state' => 'unknown'])
            : OperationOutcome::failed($failure->errorCode ?? 'instance.smoke_unknown', ['state' => 'unknown']);
    }

    private function result(Operation $operation): InstanceSmokeResultData
    {
        $stored = $operation->state['smoke'] ?? null;

        return InstanceSmokeResultData::from([...$operation->input, ...(is_array($stored) ? $stored : [])]);
    }

    private function expired(Operation $operation): bool
    {
        return $operation->created_at?->copy()->addSeconds(720)->isPast() ?? true;
    }

    private function repository(Operation $operation): GitHubRepository
    {
        $origin = $operation->input['repository_url'] ?? null;

        return (is_string($origin) ? GitHubRepository::fromOrigin($origin) : null)
            ?? throw new ResourceOperationException('instance.smoke_invalid', 'The admitted repository is invalid.');
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
