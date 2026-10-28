<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Operations\OperationRunner;
use App\Domain\Problems\ProblemRecovery;
use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertReceipt;
use App\Domain\Releases\ReleaseAlertStep;
use App\Http\Mcp\ToolManifest;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Models\ProblemFingerprint;
use Tests\Support\Fleet\RecordingReleaseAlertNotifier;
use Tests\Support\ProductionSmokeRecoveryFixture;

beforeEach(function (): void {
    $this->freezeTime();
    enable_operation_queue();
    [$this->fixture, $this->failure, $this->rollback] = ProductionSmokeRecoveryFixture::create();
    $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip]);
    $this->url = '/api/v1/instances/'.$this->fixture->instance->id.'/smoke';
    $this->control = ['action' => 'hold', 'hold_key' => 'hold.'.$this->fixture->instance->id,
        'expected' => $this->rollback['expected'], 'smoke_request_key' => null, 'rollback_request_key' => null];
    $this->notifier = new RecordingReleaseAlertNotifier;
    app()->instance(ReleaseAlertNotifier::class, $this->notifier);
});

it('durably holds both admission and queued automatic execution without disabling operator intent', function (): void {
    $instance = $this->fixture->instance;
    $this->withHeader('Idempotency-Key', 'hold')->postJson($this->url.'/control', $this->control)->assertOk()->assertJsonPath('data.held', true);
    $this->postJson($this->url.'/control', $this->control)->assertOk();
    $this->getJson($this->url.'/control/receipt?request_key=hold')->assertOk()->assertJsonPath('data.found', true);
    $this->postJson($this->url.'/control', [...$this->control, 'hold_key' => 'other'])->assertConflict();
    expect($instance->refresh()->auto_deploy)->toBeTrue();
    $this->getJson('/api/v1/instances/'.$instance->id)->assertOk()->assertJsonPath('data.auto_deploy.error_code', 'instance.smoke_held');
    expect(AutomaticDeployPolicyWriter::Swamp->eligibilityRefusal($instance))->toBe('instance.smoke_held');
    $this->withHeader('Idempotency-Key', 'auto')->postJson('/api/v1/instances/'.$instance->id.'/deploy/automatic', ['commit' => str_repeat('b', 40)])
        ->assertConflict()->assertJsonPath('error.code', 'instance.smoke_held');
    $op = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => 'queued', 'sequence' => 2, 'triggered_by' => 'swamp:auto-deploy',
        'input' => ['commit' => str_repeat('b', 40), 'caller_node_id' => $this->fixture->caller->id]]);
    app(OperationRunner::class)->run($op->id);
    expect($op->refresh()->error_code)->toBe('instance.smoke_held');
    expect($this->fixture->deployment->activations)->toBe(0);
});

it('alerts on no result but never rolls back and never accepts missing or historical pass proof', function (): void {
    $this->withHeader('Idempotency-Key', 'hold')->postJson($this->url.'/control', $this->control)->assertOk();
    $this->withHeader('Idempotency-Key', 'alert')->postJson($this->url.'/control', [...$this->control, 'action' => 'alert'])->assertOk();
    $this->postJson($this->url.'/control', [...$this->control, 'action' => 'alert'])->assertOk();
    expect($this->notifier->alerts)->toHaveCount(1);
    foreach ([null, 'missing', 'proof.passed', 'proof.failed'] as $proof) {
        $this->withHeader('Idempotency-Key', 'release')->postJson($this->url.'/control', [...$this->control, 'action' => 'release', 'smoke_request_key' => $proof])->assertStatus($proof === null ? 409 : 422);
    }
    expect(Operation::query()->where('kind', 'instance.rollback')->count())->toBe(0);
    expect($this->fixture->instance->refresh()->auto_deploy_state['smoke_control']['hold'])->not->toBeNull();
});

it('releases after restored smoke and resumes only its own recovery', function (string $pause): void {
    $instance = $this->fixture->instance;
    if ($pause === 'before') {
        InstanceDeployment::query()->create(['instance_id' => $instance->id, 'status' => 'failed', 'started_at' => now(), 'triggered_by' => 'operator']);
    }
    $this->withHeader('Idempotency-Key', 'hold')->postJson($this->url.'/control', $this->control)->assertOk();
    $this->withHeader('Idempotency-Key', $this->rollback['failure_key'])->postJson($this->url.'/failure', $this->failure)->assertOk();
    $key = $this->rollback['failure_key'].'.rollback';
    $this->withHeader('Idempotency-Key', $key)->postJson($this->url.'/rollback', $this->rollback)->assertAccepted();
    app(OperationRunner::class)->run(Operation::query()->where('kind', 'instance.rollback')->sole()->id);
    $row = InstanceDeployment::query()->where('kind', 'rollback')->sole();
    // Reuse verified artifacts, bound to the new deployment, as a restored smoke result.
    $proof = Operation::query()->where('idempotency_key', 'proof.passed')->sole()->replicate();
    $input = $proof->input;
    $state = $proof->state;
    $input['deployment_id'] = $state['smoke']['deployment_id'] = $row->id;
    $proof->fill(['input' => $input, 'state' => $state, 'idempotency_key' => 'restored'])->save();
    if ($pause === 'operator') {
        $this->patchJson('/api/v1/instances/'.$instance->id, ['auto_deploy' => true])->assertOk();
    }
    if ($pause === 'disabled') {
        $this->patchJson('/api/v1/instances/'.$instance->id, ['auto_deploy' => false])->assertOk();
    }
    if ($pause === 'manual') {
        InstanceDeployment::query()->create(['instance_id' => $instance->id, 'status' => 'failed', 'started_at' => now(), 'triggered_by' => 'operator']);
    }
    $this->withHeader('Idempotency-Key', 'release')->postJson($this->url.'/control', [...$this->control, 'action' => 'release',
        'expected' => ['deployment_id' => $row->id, 'commit' => $row->commit, 'release' => $row->release],
        'smoke_request_key' => 'restored', 'rollback_request_key' => $key])->assertOk()->assertJsonPath('data.held', false)
        ->assertJsonPath('data.resumed', $pause === 'none');
    $this->postJson($this->url.'/control', [...$this->control, 'action' => 'release',
        'expected' => ['deployment_id' => $row->id, 'commit' => $row->commit, 'release' => $row->release],
        'smoke_request_key' => 'restored', 'rollback_request_key' => $key])->assertOk();
    expect($instance->refresh()->auto_deploy_state['blocked'])->toContain($this->failure['commit']);
    expect($instance->auto_deploy_state['smoke_control']['hold'])->toBeNull();
})->with(['none', 'before', 'operator', 'disabled', 'manual']);

it('marks queued-auto cancellation and guards a pinned owned recovery redeploy', function (bool $changed): void {
    $instance = $this->fixture->instance;
    $this->withHeader('Idempotency-Key', 'hold')->postJson($this->url.'/control', $this->control)->assertOk();
    $this->withHeader('Idempotency-Key', $this->rollback['failure_key'])->postJson($this->url.'/failure', $this->failure)->assertOk();
    $automatic = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => 'queued', 'sequence' => 1, 'triggered_by' => 'swamp:auto-deploy', 'lock_key' => 'instance:'.$instance->id, 'input' => []]);
    $key = $this->rollback['failure_key'].'.rollback';
    $this->withHeader('Idempotency-Key', $key)->postJson($this->url.'/rollback', $this->rollback)->assertAccepted()->assertJsonPath('data.input.recovery_redeploy_required', true);
    expect($automatic->refresh()->status->value)->toBe('cancelled');
    expect($automatic->error_code)->toBe('operation.superseded');
    app(OperationRunner::class)->run(Operation::query()->where('kind', 'instance.rollback')->sole()->id);
    $row = InstanceDeployment::query()->where('kind', 'rollback')->sole();
    $data = [...$this->control, 'action' => 'redeploy', 'expected' => ['deployment_id' => $row->id, 'commit' => $row->commit, 'release' => $row->release], 'rollback_request_key' => $key];
    $this->withHeader('Idempotency-Key', 'redeploy')->postJson($this->url.'/redeploy', $data)->assertAccepted()->assertJsonPath('data.input.commit', $row->commit);
    $op = Operation::query()->where('idempotency_key', 'redeploy')->sole();
    $this->postJson($this->url.'/redeploy', $data)->assertAccepted()->assertJsonPath('data.id', $op->id);
    if ($changed) {
        $this->fixture->deployment->current = 'initial';
    }
    app(OperationRunner::class)->run($op->id);
    expect($op->refresh()->error_code)->toBe($changed ? 'instance.smoke_selection_changed' : null);
    $this->getJson($this->url.'/redeploy/receipt?request_key=redeploy')->assertOk()->assertJsonPath('data.operation.status', $changed ? 'failed' : 'succeeded');
    if (! $changed) {
        expect($this->fixture->deployment->preparedCommits)->toBe([$row->commit]);
    }
})->with([true, false]);

it('retains partial alerts, retries the bound request, and resolves only owned assistance after a fresh pass', function (): void {
    $instance = $this->fixture->instance;
    $this->fixture->deployment->current = 'fresh';
    $this->control['expected'] = $this->rollback['target'];
    $this->withHeader('Idempotency-Key', 'hold')->postJson($this->url.'/control', $this->control)->assertOk();
    $owned = ProblemFingerprint::query()->create(['fingerprint' => str_repeat('1', 64), 'source' => 'release',
        'evidence' => ['incident_resource' => 'instance:'.$instance->id, 'incident_attempt' => $this->control['hold_key'], 'release_kind' => 'instance_smoke_held']]);
    $other = ProblemFingerprint::query()->create(['fingerprint' => str_repeat('2', 64), 'source' => 'release', 'evidence' => []]);
    app()->instance(ReleaseAlertNotifier::class, new class($owned->fingerprint) implements ReleaseAlertNotifier
    {
        public int $calls = 0;

        public function __construct(private string $fingerprint) {}

        public function alert(ReleaseAlert $alert): ReleaseAlertReceipt
        {
            return new ReleaseAlertReceipt('fixture', 7, ReleaseAlertStep::done($this->fingerprint),
                ++$this->calls === 1 ? ReleaseAlertStep::failed('error') : ReleaseAlertStep::done());
        }
    });
    $alert = [...$this->control, 'action' => 'alert'];
    $this->withHeader('Idempotency-Key', 'alert')->postJson($this->url.'/control', $alert)->assertOk()->assertJsonPath('data.reason', 'release.alert_delivery_failed');
    $this->postJson($this->url.'/control', [...$alert, 'smoke_request_key' => 'changed'])->assertConflict();
    $this->postJson($this->url.'/control', $alert)->assertOk()->assertJsonPath('data.reason', null);
    expect(app(ProblemRecovery::class)->reason($owned))->toBeNull();
    $proof = Operation::query()->where('idempotency_key', 'proof.passed')->sole()->replicate();
    $proof->idempotency_key = 'fresh-pass';
    $proof->save();
    $this->withHeader('Idempotency-Key', 'release')->postJson($this->url.'/control', [...$this->control, 'action' => 'release', 'smoke_request_key' => 'fresh-pass'])->assertOk();
    expect($owned->refresh()->evidence['resolution'])->toBe('verified_production_smoke');
    expect($other->refresh()->evidence)->not->toHaveKey('resolved_at');
});

it('refuses a caller substitution and ineligible public ownership without changing the hold', function (string $change): void {
    if ($change === 'caller') {
        $this->withHeader('Idempotency-Key', 'hold')->postJson($this->url.'/control', $this->control)->assertOk();
        $this->fixture->owner->accessibleNodes()->attach($this->fixture->caller->id);
        $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->owner->wireguard_ip]);
    } else {
        $this->fixture->instance->routes()->first()->update(['publication' => 'private']);
    }
    $this->withHeader('Idempotency-Key', 'hold')->postJson($this->url.'/control', $this->control)->assertConflict();
    expect($this->fixture->deployment->activations)->toBe(0);
})->with(['caller', 'private']);

it('advertises the required control key and transports it through MCP', function (): void {
    $tool = collect(ToolManifest::default()->definitions())
        ->first(fn ($tool): bool => $tool->name === 'instance-smoke-control');
    expect($tool?->inputSchema['required'] ?? [])->toContain('Idempotency-Key');
    $result = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'instance-smoke-control', 'arguments' => [
            ...$this->control, 'instance' => $this->fixture->instance->id, 'Idempotency-Key' => 'mcp-hold',
        ]],
    ])->assertOk()->assertJsonPath('result.isError', false);
    $body = json_decode($result->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
    expect($body['data']['held'])->toBeTrue();
    expect($body['data']['request_key'])->toBe('mcp-hold');
});
