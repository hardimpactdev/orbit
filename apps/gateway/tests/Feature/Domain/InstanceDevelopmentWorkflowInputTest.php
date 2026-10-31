<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Domain\Instances\Deployment\InstanceDeployProjection;
use App\Domain\Instances\Deployment\InstanceDevelopmentWorkflowInput;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\InstanceDeployment;
use App\Models\ProjectDevelopmentDeployStep;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->processes = new class implements ProcessRunner
    {
        public array $values = [];

        public array $argv = [];

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->values[] = stream_get_contents($invocation->protectedInput->stream());
            $this->argv[] = $invocation->arguments;

            return new CommandResult(0, '{}', '', 1, false);
        }
    };
    app()->instance(ProcessRunner::class, $this->processes);
    config(['swamp.run_token' => 'synthetic.private-run-token']);
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    $this->instance = dev935_instance('native-shape');
    dev935_steps($this->instance, [new DevelopmentDeployStep('install', 'printf private-command-value', 300, true),
        new DevelopmentDeployStep('optional-build', 'exit 1', 60, false)]);
    $this->operation = app(StartInstanceDeployAction::class)->execute($this->instance, 'operator', null, str_repeat('a', 40));

});

it('passes the full immutable public intent and ordered command references to StartSwampRun', function (): void {
    $inputs = $this->socket->sent[0]['payload']['inputs'];
    expect($inputs)->toMatchArray(['operation_id' => $this->operation->id, 'node_id' => $this->instance->node_id,
        'kind' => 'instance.deploy'])
        ->and($inputs['intent'])->toMatchArray(['instance_id' => $this->instance->id, 'project_id' => $this->instance->project_id, 'commit' => str_repeat('a', 40)])
        ->and($inputs['intent']['deployment']['home'])->toBe($this->instance->checkout_path)
        ->and($inputs['intent']['deployment']['node_user'])->toBe($this->instance->node->user)
        ->and($inputs['intent']['deployment']['steps'][0]['order'])->toBe(0)
        ->and($inputs['intent']['deployment']['steps'][1]['order'])->toBe(1)
        ->and($inputs['intent']['deployment']['steps'][1]['step_name'])->toBe('application-1')
        ->and($inputs['intent']['deployment']['steps'][1]['required'])->toBeFalse()
        ->and($inputs['execution_secrets']['command_0']['vault'])->toBe('orbit-control-inputs')
        ->and($this->processes->values)->toBe(['printf private-command-value', 'exit 1', '{"github_token":null}'])
        ->and(json_encode($inputs))->not->toContain('private-command-value', 'synthetic.private-run-token', '$secret')
        ->and(json_encode($this->processes->argv))->not->toContain('private-command-value');
});

it('dispatches the admitted command reference after later Project commands change', function (): void {
    ProjectDevelopmentDeployStep::query()->where('project_id', $this->instance->project_id)
        ->where('name', 'install')->update(['command' => 'changed command']);
    $values = $this->processes->values;
    $inputs = app(DirectOperation::class)->workflowInputs($this->operation);
    expect($this->processes->values)->toBe($values)
        ->and($inputs['intent']['deployment']['steps'][0]['command_digest'])
        ->toBe(hash('sha256', 'printf private-command-value'));
});

it('keeps public Operation reads free of execution-copy effects', function (): void {
    $values = $this->processes->values;
    $input = app(InstanceDeployProjection::class)->input($this->operation);
    expect($this->processes->values)->toBe($values)->and($input)->toHaveKeys(['intent', 'execution_secrets']);
});

it('does not change production intent or copy development programs for production', function (): void {
    $input = ['deployment' => ['variant' => 'production'], 'instance_id' => $this->instance->id, 'commit' => ''];
    expect(app(InstanceDevelopmentWorkflowInput::class)->capture($this->instance, $input))->toBe($input);
});

it('refuses a changed command during admission before copying material', function (): void {
    $input = $this->operation->input;
    $values = $this->processes->values;
    ProjectDevelopmentDeployStep::query()->where('project_id', $this->instance->project_id)
        ->where('name', 'install')->update(['command' => 'changed command']);
    expect(fn () => app(InstanceDevelopmentWorkflowInput::class)->capture($this->instance, $input))
        ->toThrow(ResourceOperationException::class);
    expect($this->processes->values)->toBe($values);
});

it('projects the declared native result into deployment history without a family callback', function (string $status): void {
    $operation = $this->operation->fresh();
    $runId = $operation->state['swamp_run_id'];
    $before = $this->instance->fresh()->seed_commit;
    $copies = $this->processes->values;
    $result = ['instance_id' => $this->instance->id, 'commit' => str_repeat('a', 40),
        'selection_confirmed' => true, 'failed_step' => $status === 'failed' ? 'before_activation.install' : null,
        'error_code' => $status === 'failed' ? 'deployment.step_failed' : null];
    $history = ['id' => $runId, 'workflowName' => 'operation-instance-deploy-direct', 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'instance.deploy'],
        'startedAt' => '2026-10-31T13:00:00Z', 'completedAt' => '2026-10-31T13:01:00Z',
        'jobs' => [['name' => 'deployment', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => json_encode($result)]]]]]];
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'],
        'payload' => ['data' => $history]]];
    app()->instance(WebSocketClient::class, $socket);
    $projected = app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $runId, 'status' => $status],
        app(DirectOperation::class)->definition('instance.deploy')['terminal_result']);

    expect($projected->status)->toBe(OperationStatus::from($status))->and($projected->lock_key)->toBeNull()
        ->and($socket->sent)->toHaveCount(1)->and($socket->sent[0]['type'])->toBe('workflow.history.get')
        ->and($this->processes->values)->toBe($copies)
        ->and($this->instance->fresh()->seed_commit)->toBe($status === 'succeeded' ? str_repeat('a', 40) : $before);
    $deployment = InstanceDeployment::query()->findOrFail($projected->result['deployment_id']);
    expect($deployment->status)->toBe($status === 'cancelled' ? 'failed' : $status)->and($deployment->commit)->toBe(str_repeat('a', 40));
})->with(['succeeded', 'failed', 'cancelled']);
