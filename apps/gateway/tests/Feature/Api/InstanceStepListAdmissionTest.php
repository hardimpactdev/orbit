<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceStepListAction;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Operation;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    config(['swamp.run_token' => 'fixture.shared.token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-instance-setup-direct', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
});

it('records setup intent and returns a keyed Operation without dispatching PHP', function (): void {
    $instance = dev935_instance();
    $instance->node->accessibleNodes()->attach($instance->node_id);
    Queue::fake();
    $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip]);
    $url = '/api/v1/instances/'.$instance->id.'/setup';
    $first = $this->withHeader('Idempotency-Key', 'setup-proof')->postJson($url)->assertAccepted();
    $this->postJson($url)->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
    expect(Operation::query()->count())->toBe(1)
        ->and(Operation::query()->sole()->input)->toBe([
            'instance_id' => $instance->id,
            'source' => 'project', 'list' => 'setup',
            'configuration_digest' => hash('sha256', '[]'),
            ...app(StartInstanceStepListAction::class)->appStepAdmission($instance, LifecyclePhase::Setup),
        ]);
    Queue::assertNothingPushed();
});

it('keeps Project commands out of admitted setup input and uses one digest rule', function (): void {
    $instance = dev935_instance();
    $steps = app(ProjectLifecycleStepStore::class);
    $steps->create($instance->project, LifecyclePhase::Setup, new LifecycleStep('install', 'synthetic-private-script', 30), null, null);
    $admission = app(StartInstanceStepListAction::class);
    $operation = $admission->execute($instance, LifecyclePhase::Setup, 'operator', null);
    expect($operation->input['configuration_digest'])->toBe($admission->configurationDigest($instance->project, LifecyclePhase::Setup))
        ->and(json_encode($operation->input))->not->toContain('synthetic-private-script');
});

it('derives development execution from placement and ignores caller account fields', function (): void {
    $instance = dev935_instance();
    $instance->node->update(['user' => 'nckrtl']);
    $instance->node->accessibleNodes()->attach($instance->node_id);
    $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip]);
    $response = $this->postJson('/api/v1/instances/'.$instance->id.'/setup', [
        'environment' => 'production', 'execution' => ['user' => 'root', 'directory' => '/root'],
    ])->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->input['environment'])->toBe('development')
        ->and($operation->input['execution']['user'])->toBe('nckrtl')
        ->and($operation->input['execution']['directory'])->toBe($instance->checkout_path)
        ->and($operation->input['execution']['variant'])->toBe('checkout');
});

it('refuses a root execution account or unsafe checkout before recording intent', function (string $change): void {
    $instance = dev935_instance();
    if ($change === 'root') {
        $instance->node->update(['user' => 'root']);
    } else {
        $instance->update(['checkout_path' => '/srv/apps/../foreign']);
    }
    expect(fn () => app(StartInstanceStepListAction::class)->execute($instance, LifecyclePhase::Setup, 'operator', null))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0);
})->with(['root', 'path']);
