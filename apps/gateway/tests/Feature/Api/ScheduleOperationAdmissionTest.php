<?php

declare(strict_types=1);

use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\Operations\OperationStatus;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    app()->instance(ScheduleRuntimeAccountResolver::class, new FakeScheduleRuntimeAccountResolver);
    $this->node = $this->markAsGateway(Node::query()->create([
        'name' => 'schedule-admission', 'status' => 'active', 'platform' => 'linux',
        'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->payload = ['target_type' => 'node', 'target_id' => $this->node->id, 'name' => 'throwaway', 'calendar' => 'daily', 'command' => 'printf private-schedule-command'];
});

it('records timer intent with a command reference and replays its UUID', function (): void {
    $response = $this->withHeader('Idempotency-Key', 'schedule-proof')->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    $operation = Operation::query()->sole();
    expect($response->getContent())->not->toContain('private-schedule-command')
        ->and($operation->input['command'])->toBe(['$secret' => 'schedule.command', 'id' => $this->node->id])
        ->and($operation->input['desired'])->toBe('enabled')
        ->and(Schedule::query()->sole()->status->value)->toBe('provisioning');
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted()->assertJsonPath('data.id', $operation->id);
});

it('refuses changed specifications and disabled Node timers before recording another Operation', function (): void {
    $this->postJson('/api/v1/schedules', [...$this->payload, 'start' => false])->assertUnprocessable();
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    $this->postJson('/api/v1/schedules', [...$this->payload, 'command' => 'false'])->assertConflict()->assertJsonPath('error.code', 'schedule.retry_conflict');
    expect(Operation::query()->count())->toBe(1);
});

it('admits a manual run without changing desired timer state', function (): void {
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    Operation::query()->sole()->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $schedule = Schedule::query()->sole();
    $schedule->update(['status' => 'active']);
    $this->postJson('/api/v1/schedules/'.$schedule->id.'/run')->assertAccepted()->assertJsonPath('data.kind', 'schedule.run');
    expect($schedule->refresh()->desired_timer_state->value)->toBe('enabled');
});

it('holds the owning Node across concurrent changes to different Schedules', function (): void {
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    $this->postJson('/api/v1/schedules', [...$this->payload, 'name' => 'another-timer'])
        ->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    expect(Schedule::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1);
});

it('admits a receipt read before the first effect and refuses an undeclared Schedule step', function (): void {
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    $operation = Operation::query()->sole();
    config(['dependency_detection.services_node_id' => $this->node->id]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'schedule.create', 'worker' => 'schedule-worker'])->assertOk();
    $this->getJson('/api/v1/operation-executor/'.$operation->id.'/receipts/schedule-install')->assertOk()->assertJsonPath('data.state', 'unknown');
    $this->getJson('/api/v1/operation-executor/'.$operation->id.'/receipts/unrelated')->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($operation->refresh()->state)->not->toHaveKey('executor_runs');
});

it('recovers a lost manual-run reply through the target receipt without another invocation', function (): void {
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    Operation::query()->sole()->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $schedule = Schedule::query()->sole();
    $schedule->update(['status' => 'active']);
    $response = $this->postJson('/api/v1/schedules/'.$schedule->id.'/run')->assertAccepted();
    $id = $response->json('data.id');
    $executor = Node::query()->create(['name' => 'separate-executor', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.4', 'public_ssh_host' => '192.0.2.4']);
    $executor->accessibleNodes()->attach($this->node);
    config(['dependency_detection.services_node_id' => $executor->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $executor->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'schedule.run', 'worker' => 'schedule-worker'])->assertOk();
    $agent = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agent);
    $effectKey = null;
    $identity = null;
    $agent->answer('schedule.run', function (array $input) use (&$effectKey, &$identity): array {
        $identity = $input[EffectReceiptMetadata::InputKey]->identity();
        $effectKey = $input['effect_key'];
        throw new RuntimeException('Lost Schedule reply');
    });
    $agent->answer('agent.effect-receipt', function (array $input) use (&$effectKey, &$identity): array {
        expect($input['effect_key'])->toBe($effectKey);

        return ['command' => 'schedule.run', 'effect_key' => $effectKey, 'state' => 'succeeded', 'sanitized' => true,
            'effect_nonce' => $identity['nonce'], 'effect_created_at' => $identity['created_at'],
            'exit_code' => 0, 'result_digest' => str_repeat('a', 64), 'finished_at' => '2026-10-28T22:00:00Z',
            'result' => ['ok' => true, 'data' => []]];
    });
    $run = ['worker' => 'schedule-worker', 'key' => 'schedule-run', 'transport' => 'agent', 'name' => 'schedule.run',
        'input' => ['id' => $schedule->id, 'user' => 'orbit', 'working_directory' => '/home/orbit']];
    $path = '/api/v1/operation-executor/'.$id;
    $this->postJson($path.'/run', $run)->assertConflict();
    expect(array_column($agent->calls, 'command'))->toBe(['schedule.run']);
    expect($effectKey)->toBe('op-'.$id.'.schedule-run');
    $this->getJson($path.'/receipts/schedule-run')->assertOk()->assertJsonPath('data.state', 'succeeded');
    $this->getJson($path.'/receipts/schedule-run')->assertOk()->assertJsonPath('data.state', 'succeeded');
    $this->postJson($path.'/step', ['worker' => 'schedule-worker', 'key' => 'done', 'name' => 'schedule-run', 'status' => 'succeeded', 'result' => []])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'schedule-worker', 'status' => 'succeeded', 'result' => []])->assertOk();
    expect(array_column($agent->calls, 'command'))->toBe(['schedule.run', 'agent.effect-receipt']);
});
