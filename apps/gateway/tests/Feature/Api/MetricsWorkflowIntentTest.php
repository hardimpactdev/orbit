<?php

declare(strict_types=1);

use App\Domain\Metrics\AdmitMetricsFleetAction;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Metrics\MetricsFleetWorkflowIntent;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Activity;
use App\Models\Operation;
use Tests\Support\MetricsExporterPreferenceFixture;

beforeEach(function (): void {
    $this->fixture = new MetricsExporterPreferenceFixture;
    config(['swamp_workers.nodes' => [1, 9, 13, 18]]);
    $this->plan = app(MetricsFleetCapture::class)->capture();
});
afterEach(function (): void {
    $this->fixture->close();
});
it('captures the complete public fleet including both logical proxy participants', function (): void {
    $intent = app(MetricsFleetWorkflowIntent::class)->capture($this->plan);
    expect($intent['participant_node_ids'])->toBe([1, 9, 13, 18])
        ->and($intent['metrics_fleet'])->toBe($this->plan)
        ->and($intent)->not->toHaveKey('execution_secrets')
        ->and($intent)->not->toHaveKey('fleet_runtime')
        ->and($intent['metrics_fleet']['runtime_files'])->not->toHaveKey('/etc/orbit/metrics/grafana/admin-password');
});
it('refuses a missing exact worker without filtering it from the fleet', function (): void {
    $plan = $this->plan;
    $plan['fleet'][0]['worker'] = false;
    unset($plan['digest']);
    $plan['digest'] = MetricsFleetCapture::digest($plan);
    expect(fn () => app(MetricsFleetWorkflowIntent::class)->capture($plan))->toThrow(ResourceOperationException::class);
});
it('returns only immutable public intent to StartSwampRun without reading current credentials or publications', function (): void {
    $input = app(MetricsFleetWorkflowIntent::class)->capture($this->plan);
    $operation = new Operation(['kind' => 'metrics.reconcile', 'input' => [...$input,
        'execution_secrets' => ['grafana_password' => ['vault' => 'orbit-control-inputs', 'key' => 'metrics-grafana-node-9-active']]]]);
    $this->fixture->metrics->update(['name' => 'changed-after-admission']);
    expect(app(MetricsFleetWorkflowIntent::class)->fromInput($operation->input))->toBe($input);
});
it('refuses changed public intent before submission', function (): void {
    $plan = $this->plan;
    $plan['runtime_files']['/etc/orbit/metrics/prometheus.yml'] .= "\nchanged";
    expect(fn () => app(MetricsFleetWorkflowIntent::class)->capture($plan))->toThrow(ResourceOperationException::class);
});

it('submits full public intent and only named execution descriptors through the shared start boundary', function (): void {
    $credential = 'disposable-metrics-password-sentinel';
    $credentials = Mockery::mock(MetricsCredentialManager::class);
    $credentials->shouldReceive('passwordForConvergence')->once()->andReturn($credential);
    app()->instance(MetricsCredentialManager::class, $credentials);
    $runner = new class implements ProcessRunner
    {
        public array $values = [];

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->values[] = stream_get_contents($invocation->protectedInput->stream());

            return new CommandResult(0, '{}', '', 1, false);
        }
    };
    app()->instance(ProcessRunner::class, $runner);
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->fixture->socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-metrics-fleet', 'runId' => $run,
    ]]];
    $operation = app(AdmitMetricsFleetAction::class)->execute();
    $payload = $this->fixture->socket->sent[0]['payload']['inputs'];
    expect($operation->state['swamp_run_id'])->toBe($run)
        ->and($payload['operation_id'])->toBe($operation->id)
        ->and($payload['node_id'])->toBe(9)->and($payload['kind'])->toBe('metrics.reconcile')
        ->and($payload['intent']['participant_node_ids'])->toBe([1, 9, 13, 18])
        ->and($payload['intent']['metrics_fleet'])->toBe($this->plan)
        ->and($payload['execution_secrets'])->toBe(['grafana_password' => ['vault' => 'orbit-control-inputs', 'key' => 'metrics-grafana-node-9-active']])
        ->and($runner->values)->toBe([$credential]);
    expect(json_encode([$this->fixture->socket->sent, $operation->fresh()->toArray(), Activity::query()->get()->toArray()], JSON_THROW_ON_ERROR))->not->toContain($credential);
});

it('projects the fixed terminal stdout with one shared history read and no family callback', function (): void {
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $input = app(MetricsFleetWorkflowIntent::class)->capture($this->plan);
    $operation = Operation::query()->create(['kind' => 'metrics.reconcile', 'target_type' => 'metrics', 'target_id' => 9,
        'node_id' => 9, 'status' => OperationStatus::Running, 'sequence' => 1,
        'state' => ['swamp_run_id' => $run], 'input' => [...$input, 'execution_secrets' => ['grafana_password' => ['vault' => 'orbit-control-inputs', 'key' => 'metrics-grafana-node-9-active']]]]);
    $result = ['ready' => true, 'verified_node_ids' => [1, 9, 13, 18], 'runtime_committed' => true];
    $this->fixture->socket->reply = static fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => $run, 'status' => 'succeeded', 'workflowName' => 'operation-metrics-fleet',
        'inputs' => ['operation_id' => $operation->id, 'kind' => 'metrics.reconcile', 'node_id' => 9],
        'startedAt' => '2026-10-31T13:00:00Z', 'completedAt' => '2026-10-31T13:00:01Z',
        'jobs' => [['name' => 'result', 'steps' => [['name' => 'summary', 'outputs' => ['stdout' => json_encode($result, JSON_THROW_ON_ERROR)]]]]],
    ]]]];
    $done = app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $run, 'status' => 'succeeded'], ['job' => 'result', 'step' => 'summary']);
    expect($done->status)->toBe(OperationStatus::Succeeded)->and($done->result)->toBe($result)
        ->and($this->fixture->socket->sent)->toHaveCount(1)
        ->and($this->fixture->socket->sent[0]['type'])->toBe('workflow.history.get');
});
