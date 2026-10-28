<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\Deployment\InstanceDeploymentRecorder;
use App\Http\Mcp\ToolManifest;
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

});

it('publishes the required automatic key and commit in the generated MCP contract', function (): void {
    $tool = collect(ToolManifest::default()->definitions())->first(fn ($tool): bool => $tool->name === 'instance-deploy-automatic');
    expect($tool?->inputSchema['required'])->toEqualCanonicalizing(['instance', 'commit', 'Idempotency-Key']);
});

it('keeps historical automatic labels and missing caller IDs unchanged', function (): void {
    $row = app(InstanceDeploymentRecorder::class)->start($this->fixture->instance, 'orbit:auto-deploy');

    $this->getJson('/api/v1/deployments/'.$row->id)->assertOk()
        ->assertJsonPath('data.triggered_by', 'orbit:auto-deploy')->assertJsonPath('data.caller_node_id', null)->assertJsonPath('data.caller_node_name', null);
    expect(new AutomaticDeployHistory($this->fixture->instance)->pausedBy())->toBeNull();
});

it('keeps the current caller name on automatic admission, replay, receipt and cancellation', function (): void {
    $id = pinned_deploy_start($this, true)->assertAccepted()
        ->assertJsonPath('data.caller_node_name', $this->fixture->caller->name)->json('data.id');
    $this->fixture->caller->update(['name' => 'renamed-policy-peer']);

    pinned_deploy_start($this, true)->assertAccepted()->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.caller_node_name', 'renamed-policy-peer');
    $this->getJson('/api/v1/instances/'.$this->fixture->instance->id.'/deploy/automatic/receipt?request_key=deploy.17.b&commit='.$this->sha)
        ->assertOk()->assertJsonPath('data.operation.caller_node_name', 'renamed-policy-peer');
    $this->call('POST', '/api/v1/operations/'.$id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.caller_node_name', 'renamed-policy-peer');
});

it('loads deployment caller names in one batch for the list', function (): void {
    for ($index = 0; $index < 5; $index++) {
        InstanceDeployment::query()->create([
            'instance_id' => $this->fixture->instance->id, 'kind' => 'deploy',
            'status' => 'succeeded', 'started_at' => now(), 'triggered_by' => 'swamp:auto-deploy',
        ]);
    }
    $url = '/api/v1/instances/'.$this->fixture->instance->id.'/deployments';
    $this->getJson($url)->assertOk();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson($url)->assertOk()->assertJsonCount(5, 'data');
    $withoutCallers = count(DB::getQueryLog());
    InstanceDeployment::query()->update(['caller_node_id' => $this->fixture->caller->id]);
    DB::flushQueryLog();

    $this->getJson($url)->assertOk()->assertJsonCount(5, 'data')
        ->assertJsonPath('data.0.caller_node_name', $this->fixture->caller->name);
    $withCallers = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($withCallers)->toBe($withoutCallers + 1);
});

it('reads operation caller names within the existing query budget', function (bool $list): void {
    for ($index = 0; $index < 5; $index++) {
        $operation = Operation::query()->create([
            'kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $this->fixture->instance->id,
            'node_id' => $this->fixture->owner->id, 'input' => [], 'status' => 'succeeded',
            'sequence' => 1, 'attempts' => 1, 'triggered_by' => 'swamp:auto-deploy',
        ]);
    }
    $url = '/api/v1/operations'.($list ? '' : '/'.$operation->id);
    $this->getJson($url)->assertOk();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson($url)->assertOk();
    $withoutCallers = count(DB::getQueryLog());
    Operation::query()->update(['input' => ['caller_node_id' => $this->fixture->caller->id]]);
    DB::flushQueryLog();

    $this->getJson($url)->assertOk()
        ->assertJsonPath($list ? 'data.0.caller_node_name' : 'data.caller_node_name', $this->fixture->caller->name);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(count($queries))->toBe($withoutCallers);
    $operationReads = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'from "operations"') || str_contains($query['query'], 'from "operation_steps"'));
    expect(count($operationReads))->toBe($list ? 1 : 2);
})->with(['list' => true, 'show' => false]);

it('does not look up caller names for automatic history on the instance list', function (): void {
    $project = $this->fixture->instance->project->replicate()->fill(['name' => 'Second Project', 'slug' => 'second-project', 'code' => null, 'repository_url' => 'https://example.test/second-project.git']);
    $project->save();
    $second = $this->fixture->instance->replicate()->fill(['name' => 'second-production', 'project_id' => $project->id, 'checkout_path' => '/home/second-project/releases/initial', 'production_user' => 'second-project', 'production_home' => '/home/second-project']);
    $second->save();
    foreach ([$this->fixture->instance, $second] as $instance) {
        for ($index = 0; $index < 5; $index++) {
            InstanceDeployment::query()->create([
                'instance_id' => $instance->id, 'kind' => 'deploy', 'status' => 'succeeded',
                'started_at' => now(), 'triggered_by' => 'swamp:auto-deploy',
            ]);
        }
    }
    $this->getJson('/api/v1/instances')->assertOk();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson('/api/v1/instances')->assertOk()->assertJsonCount(2, 'data');
    $withoutCallers = count(DB::getQueryLog());
    InstanceDeployment::query()->update(['caller_node_id' => $this->fixture->caller->id]);
    DB::flushQueryLog();

    $response = $this->getJson('/api/v1/instances')->assertOk()->assertJsonCount(2, 'data');
    $withCallers = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($response->json('data.0.auto_deploy.history_version'))->toBeString();
    expect($response->json('data.1.auto_deploy.history_version'))->toBeString();
    expect($withCallers)->toBe($withoutCallers);
});
