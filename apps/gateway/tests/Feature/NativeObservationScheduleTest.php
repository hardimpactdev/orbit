<?php

declare(strict_types=1);

use App\Domain\Observation\ScheduledObservation;
use App\Domain\Operations\DirectOperation;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Jobs\Observation\PublishObservationContext;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProblemCollectorState;
use App\Models\ProblemFingerprint;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Queue::fake();
    config(['orbit.gateway_queue.connection' => 'fixture.queue']);
    config(['swamp.run_token' => '']);
    $this->node = Node::query()->create(['name' => 'scheduled-gateway', 'platform' => 'linux', 'user' => 'orbit', 'status' => 'active', 'public_ssh_host' => '192.0.2.209', 'wireguard_ip' => '10.44.0.209', 'architecture' => 'x86_64', 'ssh_host_fingerprint' => 'public.fixture.fingerprint']);
    $this->node->roles()->create(['role' => 'gateway', 'status' => 'active']);
    config(['swamp.run_token' => 'fixture.metadata']);
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    $this->schedule = app(ScheduledObservation::class);
    $this->run = ['id' => 'd075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'succeeded',
        'startedAt' => now()->toISOString(), 'completedAt' => now()->addSecond()->toISOString()];
    $this->workflow = '09fb7a5a-6b19-4fb6-8f14-16382563f822';
    $this->context = ['revision' => 3, 'expectations' => str_repeat('a', 64), 'intent' => []];
    $this->state = ProblemCollectorState::withoutEvents(fn () => ProblemCollectorState::query()->create(['observation' => ['revision' => 3, 'dirty' => ['horizon' => false], 'contexts' => ['horizon' => $this->context]]]));
    $this->result = ['channel' => 'horizon', 'revision' => 3, 'expectations' => str_repeat('a', 64), 'observation' => ['report' => ['nodes' => [[
        'node_id' => $this->node->id, 'node_name' => $this->node->name, 'families' => [[
            'family' => 'process', 'checked' => 1, 'issues' => [['code' => 'process.state_mismatch', 'kind' => 'drift', 'resource_id' => 15, 'resource_name' => 'horizon', 'summary' => 'Horizon is stopped.', 'expected' => 'running', 'observed' => 'stopped']],
        ]],
    ]]]]];
});

it('records scheduled fingerprints and replay markers without a sentinel Operation', function (): void {
    $this->schedule->applyScheduled($this->workflow, $this->run, $this->result);
    $first = ProblemFingerprint::query()->firstOrFail()->getAttributes();
    $this->schedule->applyScheduled($this->workflow, $this->run, $this->result);
    $older = [...$this->run, 'id' => 'e075eb23-7ac4-4487-80d1-57a038a92676', 'startedAt' => now()->subMinute()->toISOString()];
    $this->schedule->applyScheduled($this->workflow, $older, $this->result);
    expect(ProblemFingerprint::query()->first()->getAttributes())->toBe($first)
        ->and($this->state->fresh()->observation['terminals']['horizon']['run_id'])->toBe($this->run['id'])
        ->and(Operation::query()->count())->toBe(0);
});

it('rejects stale contexts or native failures without recording healthy recovery', function (string $cause): void {
    if ($cause === 'revision') {
        $this->result['expectations'] = str_repeat('b', 64);
    } elseif ($cause === 'source') {
        $this->node->update(['name' => 'changed-after-capture']);
    } else {
        $this->run['status'] = 'failed';
        $this->result = [];
    }
    $this->schedule->applyScheduled($this->workflow, $this->run, $this->result);
    expect(ProblemFingerprint::query()->count())->toBe(0)
        ->and($this->state->fresh()->observation['terminals']['horizon']['status'])->toBe('unavailable');
})->with(['revision', 'source', 'native']);

it('declares independent fixed identities through the existing scheduled importer', function (): void {
    $definitions = app(DirectOperation::class)->scheduledResultDefinitions();
    expect($definitions[$this->workflow]['projection'])->toBe(ScheduledObservation::class)
        ->and($definitions['fb550568-ab07-4eb4-b5f4-db92e5780dc0']['workflow'])->toBe('observation-collection');
});

it('publishes new public SSH and expectation revisions after committed metadata changes', function (): void {
    Node::withoutEvents(fn () => $this->node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'public.fixture.pin']));
    $this->schedule->publish();
    $socket = app(WebSocketClient::class);
    $first = $socket->sent[0]['payload']['inputs'];
    expect($first['intent']['nodes'][0]['ssh']['host_keys'])->toBe(['ssh-ed25519 public.fixture.pin'])
        ->and($socket->sent[0]['payload']['workflowIdOrName'])->toBe('observation-context')
        ->and(Operation::query()->count())->toBe(0);
    $this->node->update(['wireguard_ip' => '10.44.0.210']);
    expect($this->state->fresh()->observation['dirty']['fleet'])->toBeTrue();
    $this->schedule->publish();
    $next = array_last(array_filter($socket->sent, fn ($frame) => $frame['payload']['inputs']['channel'] === 'fleet'))['payload']['inputs'];
    expect($next['revision'])->toBeGreaterThan($first['revision'])
        ->and($next['intent']['nodes'][0]['ssh']['host'])->toBe('10.44.0.210')
        ->and($next['expectations'])->not->toBe($first['expectations']);
    Queue::assertPushed(PublishObservationContext::class);
});

it('rolls back fingerprints and the replay marker when a terminal cursor is invalid', function (): void {
    $this->state->observation = ['contexts' => ['fleet' => $this->context], 'dirty' => ['fleet' => false]];
    $this->state->saveQuietly();
    $this->result['channel'] = 'fleet';
    $this->result['observation']['logs'] = ['signals' => [['exception_class' => 'RuntimeException']]];
    expect(fn () => $this->schedule->applyScheduled('fb550568-ab07-4eb4-b5f4-db92e5780dc0', $this->run, $this->result))->toThrow(InvalidArgumentException::class);
    expect(ProblemFingerprint::query()->count())->toBe(0)
        ->and($this->state->fresh()->observation)->not->toHaveKey('terminals');
});

it('accepts a completed read after collector-only refresh without losing its original cursor', function (): void {
    $this->result['revision'] = 2;
    $this->schedule->invalidate(false);
    $this->schedule->applyScheduled($this->workflow, $this->run, $this->result);
    expect(ProblemFingerprint::query()->count())->toBe(1)
        ->and($this->state->fresh()->observation['terminals']['horizon']['status'])->toBe('recorded');
});

it('keeps committed writes available when Horizon metadata fails with or without a queue', function (bool $queue): void {
    config(['orbit.gateway_queue.connection' => $queue ? 'fixture.queue' : '']);
    Node::withoutEvents(fn () => $this->node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'public.fixture.pin']));
    $socket = app(WebSocketClient::class);
    $socket->onConnect = static fn () => throw new RuntimeException('unavailable metadata transport');
    $this->node->update(['name' => 'committed-despite-unavailable-metadata']);
    expect($this->node->fresh()->name)->toBe('committed-despite-unavailable-metadata')
        ->and($socket->connections)->toBe([2.0])
        ->and(ProblemFingerprint::query()->count())->toBe(0);
})->with([false, true]);

it('imports a failed context tick without trusting skipped terminal output', function (): void {
    config(['swamp.read_token' => 'fixture.read', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
    $socket = app(WebSocketClient::class);
    $socket->reply = fn ($request) => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [...$this->run, 'status' => 'failed', 'workflowId' => $this->workflow, 'workflowName' => 'observation-horizon', 'jobs' => [['name' => 'observe', 'steps' => [['name' => 'context', 'status' => 'failed'], ['name' => 'result', 'status' => 'skipped', 'outputs' => ['stdout' => 'unusable result']]]]]]]]];
    expect(app(SwampCommandStep::class)->projectScheduledTerminal($this->workflow, ['id' => $this->run['id']]))->toBeTrue();
    expect($this->state->fresh()->observation['terminals']['horizon']['status'])->toBe('unavailable');
});
