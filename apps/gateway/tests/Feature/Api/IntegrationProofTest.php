<?php

declare(strict_types=1);

use App\Domain\Integrations\IntegrationExecution;
use App\Domain\Integrations\IntegrationPlan;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'collector-proof', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.19']);
    $this->plan = new IntegrationPlan($this->node->id);
    $this->plan->agent('collector-health', 'http.await-healthy', ['url' => 'http://127.0.0.1:8787/health']);
    $this->plan->child('collector-takeover-remove', 'route.destroy', $this->node->id, ['route_id' => 71, 'offline' => false]);
    $this->plan->receipt('active');
    $this->parent = Operation::query()->create(['kind' => 'ai_router.setup', 'target_type' => 'ai_router', 'target_id' => $this->node->id,
        'node_id' => $this->node->id, 'status' => 'running', 'input' => ['effects' => $this->plan->effects, 'flow' => $this->plan->flow], 'state' => []]);
});

function recordIntegrationProof(Operation $parent, string $key, array $result, string $status = 'succeeded'): void
{
    $effect = $parent->input['effects'][$key];
    $state = $parent->state ?? [];
    $state['executor_runs'][$key] = ['transport' => $effect['transport'], 'name' => $effect['name'], 'step' => $effect['step'], 'result' => $result];
    $state['steps'][$effect['step']] = $result;
    $parent->update(['state' => $state]);
    $parent->steps()->create(['name' => $effect['step'], 'position' => $parent->steps()->count() + 1, 'status' => $status, 'resumable' => true, 'sequence' => 1]);
}

function integrationProofChild(Operation $parent, string $key, string $kind, array $input, string $status): Operation
{
    $child = Operation::query()->create(['kind' => $kind, 'target_type' => 'route', 'target_id' => 71, 'node_id' => $parent->node_id,
        'status' => $status, 'input' => $input, 'parent_operation_id' => $parent->id, 'child_key' => $key, 'run_constraints' => []]);
    $child->forceFill(['parent_operation_id' => $parent->id, 'child_key' => $key, 'run_constraints' => []])->save();
    $snapshot = ['intent' => ['key' => $key, 'kind' => $kind, 'node_id' => $parent->node_id, 'input' => $input], 'admitted' => [
        'parent_operation_id' => $parent->id, 'key' => $key, 'kind' => $kind, 'node_id' => $parent->node_id,
        'target_type' => 'route', 'target_id' => 71, 'input' => $input, 'run_constraints' => []]];
    $child->forceFill(['child_admission' => $snapshot, 'child_fingerprint' => OperationChildIntent::fingerprint($snapshot)])->save();
    return $child;
}

it('keeps Route destroy unavailable until an exact healthy collector receipt and step exist', function (): void {
    $execution = app(IntegrationExecution::class);
    expect($execution->childContext($this->parent)['children'])->toBe([]);
    recordIntegrationProof($this->parent, 'collector-health', ['healthy' => false]);
    expect($execution->childContext($this->parent)['children'])->toBe([]);
    $this->parent->steps()->delete();
    recordIntegrationProof($this->parent, 'collector-health', ['healthy' => true]);
    expect($execution->childContext($this->parent)['children'])->toBe([[
        'key' => 'collector-takeover-remove', 'kind' => 'route.destroy', 'node_id' => $this->node->id, 'input' => ['route_id' => 71, 'offline' => false],
    ]]);
    expect(Operation::query()->count())->toBe(1);
});

it('requires the stamped successful child before continuing and refuses a changed child intent', function (): void {
    recordIntegrationProof($this->parent, 'collector-health', ['healthy' => true]);
    $child = integrationProofChild($this->parent, 'collector-takeover-remove', 'route.destroy', ['route_id' => 71, 'offline' => false], 'failed');
    $execution = app(IntegrationExecution::class);
    $run = $this->plan->effects['integration-receipt'];
    expect(fn () => $execution->beforeRun($this->parent, $run))->toThrow(ResourceOperationException::class);
    $child->update(['status' => 'succeeded']);
    $execution->beforeRun($this->parent, $run);
    $child->update(['input' => ['route_id' => 72, 'offline' => false]]);
    expect(fn () => $execution->beforeRun($this->parent, $run))->toThrow(ResourceOperationException::class);
});

it('requires rollback proof for a new WebSocket runtime after a recorded publication refusal', function (): void {
    $plan = new IntegrationPlan($this->node->id);
    $plan->agent('runtime', 'websocket.converge-runtime', []);
    $plan->agent('publication', 'caddy.install-certificate', []);
    $plan->agent('stop', 'websocket.remove-runtime', []);
    $flow = array_slice($plan->flow, 0, 2);
    $this->parent->update(['kind' => 'websocket.role_converge', 'input' => ['effects' => $plan->effects, 'flow' => $flow,
        'failure_flow' => [end($plan->flow)], 'failure_when' => ['step' => 'runtime', 'field' => 'applied', 'equals' => true]]]);
    recordIntegrationProof($this->parent, 'runtime', ['applied' => true]);
    recordIntegrationProof($this->parent, 'publication', ['error_code' => 'websocket.publication_refused'], 'failed');
    $execution = app(IntegrationExecution::class);
    expect(fn () => $execution->assertCompletion($this->parent, OperationOutcome::failed('websocket.publication_refused')))->toThrow(ResourceOperationException::class);
    expect(fn () => $execution->assertCompletion($this->parent, OperationOutcome::failed('websocket.rollback_failed')))->toThrow(ResourceOperationException::class);
    recordIntegrationProof($this->parent, 'stop', ['applied' => true]);
    $execution->assertCompletion($this->parent, OperationOutcome::failed('websocket.publication_refused'));
});

it('does not authorize new-runtime rollback from a forged failure step or an uncertain effect', function (): void {
    $input = $this->parent->input;
    $input['failure_flow'] = [];
    $input['failure_when'] = ['step' => 'collector-health', 'field' => 'healthy', 'equals' => true];
    $this->parent->update(['input' => $input]);
    recordIntegrationProof($this->parent, 'collector-health', ['healthy' => true]);
    $this->parent->steps()->create(['name' => 'forged', 'position' => 2, 'status' => 'failed', 'resumable' => true, 'sequence' => 2]);
    expect(app(IntegrationExecution::class)->recoveryAllowed($this->parent))->toBeFalse();
});
