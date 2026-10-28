<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\Deployment\DeploymentResult;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Instances\Deployment\DevelopmentTarget;
use App\Domain\Instances\Deployment\InstanceDeploymentRecorder;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Http\Mcp\ToolManifest;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Orb220DeploymentApiFixture;

beforeEach(function (): void {
    enable_operation_queue();
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->fixture->instance->update(['auto_deploy' => true, 'deployment_branch' => 'release']);
    $this->sha = str_repeat('b', 40);
    $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip]);
});

function pinned_deploy_start(object $test, bool $automatic = false, ?string $body = null, ?string $key = 'deploy.17.b'): TestResponse
{
    return $test->call('POST', '/api/v1/instances/'.$test->fixture->instance->id.'/deploy'.($automatic ? '/automatic' : ''), server: [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ...($key === null ? [] : ['HTTP_IDEMPOTENCY_KEY' => $key]),
    ], content: $body ?? json_encode(['commit' => $test->sha], JSON_THROW_ON_ERROR));
}

describe('public pinned deploy starts', function (): void {
    it('deploys exactly the requested commit and preserves the manual caller identity', function (): void {
        $id = pinned_deploy_start($this)->assertAccepted()->json('data.id');
        $operation = Operation::query()->findOrFail($id);
        expect($operation->input)->toBe(['commit' => $this->sha])->and($operation->triggered_by)->toBe($this->fixture->caller->name);
        run_operation_jobs();
        expect($this->fixture->deployment->preparedCommits)->toBe([$this->sha]);
        $row = InstanceDeployment::query()->sole();
        expect($row->commit)->toBe($this->sha)->and($row->branch)->toBe('release')->and($row->triggered_by)->toBe($this->fixture->caller->name);
    });

    it('rejects invalid SHA input and trigger spoofing before recording anything', function (string $body, bool $automatic): void {
        pinned_deploy_start($this, $automatic, body: $body)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        $this->assertDatabaseCount('operations', 0);
        expect($this->fixture->deployment->invocations)->toBe(0);
    })->with([
        'short' => '{"commit":"abc123"}', 'uppercase' => '{"commit":"'.str_repeat('A', 40).'"}',
        'null' => '{"commit":null}', 'wrong type' => '{"commit":42}',
        'wrong length' => '{"commit":"'.str_repeat('a', 41).'"}',
        'duplicate' => '{"commit":"'.str_repeat('a', 40).'","commit":"'.str_repeat('b', 40).'"}',
        'trigger' => '{"triggered_by":"orbit:auto-deploy"}', 'automatic' => '{"automatic":true}', 'caller' => '{"caller_node_id":1}',
        'Swamp trigger with valid commit' => '{"commit":"'.str_repeat('b', 40).'","triggered_by":"swamp:auto-deploy"}',
        'caller with valid commit' => '{"commit":"'.str_repeat('b', 40).'","caller_node_id":17}',
    ])->with([false, true]);

    it('accepts a full SHA-256 commit', function (): void {
        $this->sha = str_repeat('b', 64);
        pinned_deploy_start($this)->assertAccepted();
        expect(Operation::query()->sole()->input['commit'])->toBe($this->sha);
    });

    it('refuses an ineligible Instance or inconsistent Project repository identity', function (bool $ineligible): void {
        if ($ineligible) {
            $this->fixture->instance->update(['status' => 'reserved']);
        } else {
            DB::table('projects')->where('id', $this->fixture->instance->project_id)->update(['repository_identity' => 'example.test/another']);
        }
        pinned_deploy_start($this)->assertConflict()->assertJsonPath('error.code', $ineligible ? 'deployment_config.unavailable' : 'deployment.repository_mismatch');
        $this->assertDatabaseCount('operations', 0);
    })->with([true, false]);
});

describe('trusted automatic deploy starts', function (): void {
    it('stores caller commit and key on one automatic receipt and replays it after completion', function (): void {
        $id = pinned_deploy_start($this, true)->assertAccepted()->assertJsonPath('data.triggered_by', 'swamp:auto-deploy')->assertJsonPath('data.caller_node_id', $this->fixture->caller->id)->json('data.id');
        $operation = Operation::query()->findOrFail($id);
        expect($operation->input)->toBe(['commit' => $this->sha, 'caller_node_id' => $this->fixture->caller->id])
            ->and($operation->triggered_by)->toBe(AutomaticDeployHistory::SwampTrigger)->and($operation->idempotency_key)->toBe('deploy.17.b');
        run_operation_jobs();
        $this->fixture->instance->update(['auto_deploy' => false]);
        pinned_deploy_start($this, true)->assertAccepted()->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'succeeded');
        $this->assertDatabaseCount('operations', 1);
        expect($this->fixture->deployment->preparedCommits)->toBe([$this->sha]);
        expect(InstanceDeployment::query()->sole()->triggered_by)->toBe(AutomaticDeployHistory::SwampTrigger);
    });

    it('requires a stable key and a commit', function (bool $missingKey): void {
        pinned_deploy_start($this, true, body: $missingKey ? null : '{}', key: $missingKey ? null : 'automatic')->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        $this->assertDatabaseCount('operations', 0);
    })->with([true, false]);

    it('requires an active peer with Gateway authority rather than only target Node access', function (bool $active): void {
        $caller = Node::query()->create(['name' => 'services', 'status' => $active ? 'active' : 'failed', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.222', 'wireguard_ip' => '10.44.0.222', 'user' => 'orbit']);
        $caller->accessibleNodes()->attach($this->fixture->owner->id);
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
        pinned_deploy_start($this, true)->assertForbidden()->assertJsonPath('error.code', $active ? 'node_access.required' : 'peer.identity_unknown');
        $this->assertDatabaseCount('operations', 0);
        if ($active) {
            $caller->accessibleNodes()->attach($this->fixture->caller->id);
            pinned_deploy_start($this, true)->assertAccepted();
            expect(Operation::query()->sole()->input['caller_node_id'])->toBe($caller->id);
        }
    })->with([true, false]);

    it('refuses a disabled automatic Instance', function (): void {
        $this->fixture->instance->update(['auto_deploy' => false]);
        pinned_deploy_start($this, true)->assertConflict()->assertJsonPath('error.code', 'deployment.paused');
        $this->assertDatabaseCount('operations', 0);
    });

    it('conflicts when caller commit Instance or manual origin changes for the same key', function (string $change): void {
        pinned_deploy_start($this, true)->assertAccepted();
        if ($change === 'caller') {
            $caller = Node::query()->create(['name' => 'other', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.223', 'wireguard_ip' => '10.44.0.223', 'user' => 'orbit']);
            $caller->accessibleNodes()->attach($this->fixture->caller->id);
            $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
        } elseif ($change === 'commit') {
            $this->sha = str_repeat('c', 40);
        } elseif ($change === 'Instance') {
            $other = $this->fixture->instance->replicate();
            $other->name = 'other';
            $other->checkout_path = '/home/other/releases/initial';
            $other->production_home = '/home/other';
            $other->production_user = 'other';
            $project = $this->fixture->instance->project->replicate();
            $project->slug = 'other-project';
            $project->code = 'OTH';
            $project->repository_url = 'https://example.test/other.git';
            $project->save();
            $other->project_id = $project->id;
            $other->save();
            $this->fixture->instance->id = $other->id;
        }
        pinned_deploy_start($this, $change !== 'manual')->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
        $this->assertDatabaseCount('operations', 1);
    })->with(['caller', 'commit', 'Instance', 'manual']);

    it('rechecks a manual pause under the Instance lock before any effects', function (string $status, string $kind): void {
        pinned_deploy_start($this, true)->assertAccepted();
        $instance = $this->fixture->instance;
        $lock = Mockery::mock(InstanceEnvironmentOperationLock::class);
        $lock->shouldReceive('run')->once()->with([$instance->id], Mockery::type(Closure::class))->andReturnUsing(function (array $ids, Closure $work) use ($instance, $status, $kind): mixed {
            InstanceDeployment::query()->create(['instance_id' => $instance->id, 'branch' => 'release', 'kind' => $kind, 'triggered_by' => 'operator', 'status' => $status, 'started_at' => now()]);

            return $work();
        });
        app()->instance(InstanceEnvironmentOperationLock::class, $lock);
        run_operation_jobs();
        expect(Operation::query()->sole()->error_code)->toBe('deployment.paused')->and($this->fixture->deployment->invocations)->toBe(0);
        $this->assertDatabaseCount('instance_deployments', 1);
    })->with(['running', 'failed', 'succeeded'])->with(['deploy', 'rollback']);

    it('preserves rollback blocks through resume and pruning without a native tick', function (): void {
        $instance = $this->fixture->instance;
        $recorder = app(InstanceDeploymentRecorder::class);
        $previous = $recorder->start($instance, 'operator');
        $recorder->finish($previous, DeploymentResult::checkedOut($this->sha), []);
        $rollback = $recorder->start($instance, 'operator', InstanceDeployment::Rollback);
        $recorder->finish($rollback, DeploymentResult::checkedOut(str_repeat('a', 40)), []);
        $this->patchJson('/api/v1/instances/'.$instance->id, ['auto_deploy' => true])->assertOk();
        InstanceDeployment::query()->where('instance_id', $instance->id)->delete();
        pinned_deploy_start($this, true)->assertAccepted();
        run_operation_jobs();
        expect(Operation::query()->sole()->error_code)->toBe('deployment.commit_blocked')->and($this->fixture->deployment->invocations)->toBe(0);
    });
});

it('executes generated MCP pinned starts with the same receipt and caller contracts', function (bool $automatic): void {
    $tool = $automatic ? 'instance-deploy-automatic' : 'instance-deploy';
    $arguments = ['instance' => $this->fixture->instance->id, 'commit' => $this->sha, 'Idempotency-Key' => 'mcp.pinned'];
    $call = fn (array $input) => $this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $input],
    ])->assertOk();
    $response = $call($arguments)->assertJsonPath('result.isError', false);
    $started = json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR)['operation'];
    $id = $started['id'];
    expect($started['caller_node_id'])->toBe($automatic ? $this->fixture->caller->id : null);
    expect($started['triggered_by'])->toBe($automatic ? 'swamp:auto-deploy' : $this->fixture->caller->name);
    $again = $call($arguments)->assertJsonPath('result.isError', false);
    expect(json_decode($again->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR)['operation']['id'])->toBe($id);
    $bad = $call([...$arguments, 'commit' => str_repeat('c', 40)])->assertJsonPath('result.isError', true);
    expect(json_decode($bad->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('operation.idempotency_conflict');
    expect(Operation::query()->sole()->triggered_by)->toBe($automatic ? AutomaticDeployHistory::SwampTrigger : $this->fixture->caller->name);
    run_operation_jobs();
    expect($this->fixture->deployment->preparedCommits)->toBe([$this->sha]);
    $deploymentId = InstanceDeployment::query()->sole()->id;
    foreach (['operation-show' => ['operation' => $id], 'instance-deployment-show' => ['deployment' => $deploymentId]] as $name => $input) {
        $read = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $input],
        ])->assertOk()->assertJsonPath('result.isError', false);
        $data = json_decode($read->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR)['data'];
        expect($data['triggered_by'])->toBe($started['triggered_by']);
        expect($data['caller_node_id'])->toBe($started['caller_node_id']);
    }
})->with([false, true]);

it('rechecks enablement before starting a queued automatic effect', function (): void {
    pinned_deploy_start($this, true)->assertAccepted();
    $this->fixture->instance->update(['auto_deploy' => false]);
    run_operation_jobs();
    expect(Operation::query()->sole()->error_code)->toBe('deployment.paused');
    expect($this->fixture->deployment->invocations)->toBe(0);
    $this->assertDatabaseCount('instance_deployments', 0);
});

it('publishes the required automatic key and commit in the generated MCP contract', function (): void {
    $tool = collect(ToolManifest::default()->definitions())->first(fn ($tool): bool => $tool->name === 'instance-deploy-automatic');
    expect($tool?->inputSchema['required'])->toEqualCanonicalizing(['instance', 'commit', 'Idempotency-Key']);
});

it('pins a development default to the requested Project-branch commit and refuses automatic origin', function (): void {
    $fixture = $this->fixture;
    $fixture->owner->roles()->update(['role' => 'app-dev']);
    $fixture->instance->update(['name' => 'default', 'source_layout' => 'checkout', 'checkout_path' => '/fast/apps/deployment-stream/default']);
    $remote = Mockery::mock(DevelopmentDeployment::class);
    $remote->shouldReceive('target')->once()->with(Mockery::type(Instance::class), $this->sha)->andReturn(new DevelopmentTarget($this->sha));
    $remote->shouldReceive('checkout')->once();
    app()->instance(DevelopmentDeployment::class, $remote);
    pinned_deploy_start($this, true)->assertConflict()->assertJsonPath('error.code', 'instance.auto_deploy_role_inactive');
    pinned_deploy_start($this)->assertAccepted();
    run_operation_jobs();
    expect(Operation::query()->sole()->status->value)->toBe('succeeded');
    expect(InstanceDeployment::query()->sole()->commit)->toBe($this->sha);
    expect($fixture->deployment->invocations)->toBe(0);
});

it('does not permanently block a commit when rollback retains that same commit', function (): void {
    $instance = $this->fixture->instance;
    $recorder = app(InstanceDeploymentRecorder::class);
    $previous = $recorder->start($instance, 'operator');
    $recorder->finish($previous, DeploymentResult::checkedOut($this->sha), []);
    $rollback = $recorder->start($instance, 'operator', InstanceDeployment::Rollback);
    $recorder->finish($rollback, DeploymentResult::checkedOut($this->sha), []);
    $this->patchJson('/api/v1/instances/'.$instance->id, ['auto_deploy' => true])->assertOk();
    InstanceDeployment::query()->where('instance_id', $instance->id)->delete();
    pinned_deploy_start($this, true)->assertAccepted();
    run_operation_jobs();
    expect(Operation::query()->sole()->status->value)->toBe('succeeded');
    expect($this->fixture->deployment->preparedCommits)->toBe([$this->sha]);
});

it('shows the authorized Swamp caller in deployment and operation reads without pausing the next deploy', function (): void {
    $id = pinned_deploy_start($this, true)->assertAccepted()->json('data.id');
    run_operation_jobs();
    $row = InstanceDeployment::query()->sole();
    $callerId = $this->fixture->caller->id;
    $this->fixture->caller->update(['name' => 'renamed-gateway-peer']);

    foreach (['/api/v1/deployments/'.$row->id, '/api/v1/operations/'.$id] as $url) {
        $this->getJson($url)->assertOk()->assertJsonPath('data.triggered_by', 'swamp:auto-deploy')->assertJsonPath('data.caller_node_id', $callerId);
    }
    $this->getJson('/api/v1/instances/'.$this->fixture->instance->id.'/deployments')->assertOk()
        ->assertJsonPath('data.0.triggered_by', 'swamp:auto-deploy')->assertJsonPath('data.0.caller_node_id', $callerId);
    $this->getJson('/api/v1/operations')->assertOk()->assertJsonPath('data.0.caller_node_id', $callerId);
    expect(new AutomaticDeployHistory($this->fixture->instance)->pausedBy())->toBeNull();

    $this->sha = str_repeat('c', 40);
    pinned_deploy_start($this, true, key: 'next-swamp-deploy')->assertAccepted();
    run_operation_jobs();
    expect($this->fixture->deployment->preparedCommits)->toBe([str_repeat('b', 40), str_repeat('c', 40)]);
});

it('keeps historical automatic labels and missing caller IDs unchanged', function (): void {
    $row = app(InstanceDeploymentRecorder::class)->start($this->fixture->instance, 'orbit:auto-deploy');

    $this->getJson('/api/v1/deployments/'.$row->id)->assertOk()
        ->assertJsonPath('data.triggered_by', 'orbit:auto-deploy')->assertJsonPath('data.caller_node_id', null);
    expect(new AutomaticDeployHistory($this->fixture->instance)->pausedBy())->toBeNull();
});
