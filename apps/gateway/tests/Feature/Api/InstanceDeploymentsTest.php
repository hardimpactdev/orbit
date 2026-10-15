<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Orb220DeploymentApiFixture;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->deployUrl = "/api/v1/instances/{$this->fixture->instance->id}/deploy";
    $this->rollbackUrl = "/api/v1/instances/{$this->fixture->instance->id}/rollback";
});

it('rejects invalid deployment requests as ordinary JSON before invocation', function (
    string $endpoint,
    string $body,
): void {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->call('POST', $endpoint === 'deploy' ? $this->deployUrl : $this->rollbackUrl, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: $body);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertHeaderMissing('X-Accel-Buffering');

    expect($this->fixture->deployment->invocations)
        ->toBe(0)
        ->and(Activity::query()->latest('id')->value('command'))
        ->toBe($endpoint === 'deploy' ? 'instance:deploy' : 'instance:rollback');
})->with([
    'deploy missing body' => ['deploy', ''],
    'deploy array' => ['deploy', '[]'],
    'deploy malformed' => ['deploy', '{'],
    'deploy unknown member' => ['deploy', '{"release":"initial"}'],
    'rollback missing release' => ['rollback', '{}'],
    'rollback duplicate release' => ['rollback', '{"release":"initial","release":"fresh"}'],
    'rollback unknown member' => ['rollback', '{"release":"initial","force":true}'],
    'rollback wrong type' => ['rollback', '{"release":false}'],
    'rollback unsafe name' => ['rollback', '{"release":"../outside"}'],
]);

it('returns JSON for missing models and denied Node access before opening a stream', function (): void {
    $denied = Node::query()->create([
        'name' => 'deployment-denied',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.222',
        'wireguard_ip' => '10.44.0.222',
        'user' => 'orbit',
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->call('POST', '/api/v1/instances/999999/deploy', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: '{}')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'http.404');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $denied->wireguard_ip])
        ->call('POST', $this->deployUrl, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: '{}')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required')
        ->assertHeaderMissing('X-Accel-Buffering');

    expect($this->fixture->deployment->invocations)->toBe(0);
});

it('answers retired deployment-config and deployment-layout paths as not found without mutation', function (): void {
    $before = [
        'deployment_branch' => $this->fixture->instance->deployment_branch,
        'steps' => normalized_deploy_steps($this->fixture->instance),
    ];

    foreach ([
        ['GET', "/api/v1/instances/{$this->fixture->instance->id}/deployment-config", ''],
        ['PUT', "/api/v1/instances/{$this->fixture->instance->id}/deployment-config", '{"branch":"release","steps":[]}'],
        ['POST', "/api/v1/instances/{$this->fixture->instance->id}/deployment-layout", '{}'],
    ] as [$method, $url, $body]) {
        $this
            ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
            ->call($method, $url, server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ], content: $body)
            ->assertNotFound();
    }

    expect(Schema::hasTable('app_instance_deployment_layouts'))->toBeFalse()
        ->and($this->fixture->instance->fresh()->deployment_branch)->toBe($before['deployment_branch'])
        ->and(normalized_deploy_steps($this->fixture->instance->fresh()))->toBe($before['steps'])
        ->and($this->fixture->deployment->invocations)->toBe(0);
});

it('returns only retained names and the nullable current selection', function (): void {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->getJson("/api/v1/instances/{$this->fixture->instance->id}/releases");

    $response->assertOk()->assertExactJson([
        'data' => [
            'releases' => ['fresh', 'initial'],
            'selected_release' => 'initial',
        ],
        'meta' => ['request_id' => $response->headers->get('X-Orbit-Request-Id')],
    ]);

    expect(Activity::query()->sole()->command)
        ->toBe('instance:release:list')
        ->and(Activity::query()->sole()->properties?->toArray())
        ->not->toHaveKeys(['history', 'output', 'commit']);
});

it('stores Process restart outcomes in deployment history and operation steps', function (bool $fails): void {
    enable_operation_queue();
    $runtime = new ProcessesApiFakeRuntimeManager;
    if ($fails) {
        $runtime->restartFailure = new ProcessOperationException('restart', 'process.restart_failed', 'private runtime output');
    }
    app()->instance(ProcessRuntimeManager::class, $runtime);
    $process = Process::query()->create([
        'owner_type' => (new Instance)->getMorphClass(), 'owner_id' => $this->fixture->instance->id, 'name' => 'horizon',
        'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'horizon']],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
    ]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->call('POST', $this->deployUrl, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');
    $response->assertAccepted();
    run_operation_jobs();
    $deployment = InstanceDeployment::query()->sole();
    $this->getJson('/api/v1/deployments/'.$deployment->id)->assertOk()
        ->assertJsonPath('data.status', $fails ? 'failed' : 'succeeded')
        ->assertJsonPath('data.failed_step', $fails ? 'after_activation' : null)
        ->assertJsonPath('data.error_code', $fails ? 'process.restart_failed' : null);
    $events = collect($deployment->events)->where('type', 'output')->where('step', 'process_restart')->values();
    expect($runtime->restarted)->toBe([$process->id])
        ->and($this->fixture->deployment->current)->toBe('fresh')
        ->and($events)->toHaveCount(1)
        ->and(base64_decode($events[0]['value_base64'], strict: true))->toBe($fails
            ? "Failed to restart Process {$process->id}: process.restart_failed.\n"
            : "Restarted Process {$process->id}.\n");
    $this->getJson('/api/v1/operations/'.$response->json('data.id'))->assertOk()
        ->assertJsonFragment(['name' => 'process_restart', 'status' => $fails ? 'failed' : 'succeeded']);
    expect(in_array('finish', $this->fixture->deployment->steps, true))->toBe(! $fails);
})->with([false, true]);
