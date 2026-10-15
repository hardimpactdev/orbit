<?php

declare(strict_types=1);

use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Models\Activity;
use App\Models\InstanceDeployment;
use App\Models\Process;
use Illuminate\Support\Str;
use Tests\Support\Orb220DeploymentApiFixture;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->url = "/api/v1/instances/{$this->fixture->instance->id}/rollback";
});

it('streams the rollback phase and terminal event schema as NDJSON', function (): void {
    $requestId = (string) Str::uuid();
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->call('POST', $this->url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/x-ndjson',
            'HTTP_X_ORBIT_REQUEST_ID' => $requestId,
        ], content: '{"release":"initial"}');

    $response
        ->assertOk()
        ->assertStreamed()
        ->assertHeader('Content-Type', 'application/x-ndjson')
        ->assertHeader('X-Accel-Buffering', 'no');

    expect(Activity::query()->sole()->status)->toBe('running');

    $rawLines = array_values(array_filter(explode("\n", $response->streamedContent()), static fn (string $line): bool => trim($line) !== ''));
    $events = array_map(
        static fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
        $rawLines,
    );

    expect(array_column($events, 'sequence'))->toBe(range(1, count($events)))
        ->and(array_values(array_unique(array_column($events, 'request_id'))))->toBe([$requestId])
        ->and(array_column($events, 'type'))->toBe(['phase', 'phase', 'result'])
        ->and(array_keys($events[0]))->toBe(['type', 'sequence', 'request_id', 'phase'])
        ->and(array_keys($events[2]))->toBe(['type', 'sequence', 'request_id', 'status', 'failed_step', 'error_code', 'selected_release'])
        ->and(max(array_map('strlen', $rawLines)))->toBeLessThanOrEqual(32 * 1024)
        ->and($events[2])->toMatchArray(['status' => 'succeeded', 'failed_step' => null, 'error_code' => null, 'selected_release' => 'initial']);

    expect(Activity::query()->sole()->refresh()->properties?->get('deployment'))->toBe([
        'status' => 'succeeded',
        'selected_release' => 'initial',
        'failed_step' => null,
        'error_code' => null,
    ]);
});

it('ends a post-admission rollback failure with one failed result', function (): void {
    $this->fixture->deployment->failRollback = true;
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->call('POST', $this->url, server: ['CONTENT_TYPE' => 'application/json'], content: '{"release":"initial"}');
    $events = array_map(
        static fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", $response->streamedContent()), static fn (string $line): bool => trim($line) !== '')),
    );
    $results = array_values(array_filter($events, static fn (array $event): bool => $event['type'] === 'result'));

    expect($results)->toHaveCount(1)
        ->and($events[array_key_last($events)])->toBe($results[0])
        ->and($results[0])->toMatchArray([
            'status' => 'failed',
            'failed_step' => 'rollback_selection',
            'error_code' => 'deployment.release_missing',
            'selected_release' => 'initial',
        ])
        ->and(Activity::query()->sole()->refresh()->status)->toBe('failed')
        ->and(Activity::query()->sole()->error_code)->toBe('deployment.release_missing');
});

it('stops a rollback whose client left before its phase, without a result', function (): void {
    $this->fixture->connection->disconnected = true;
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->call('POST', $this->url, server: ['CONTENT_TYPE' => 'application/json'], content: '{"release":"initial"}');

    expect(trim($response->streamedContent()))->toBe('')
        ->and($this->fixture->deployment->invocations)->toBe(0)
        ->and(Activity::query()->sole()->refresh()->status)->toBe('failed')
        ->and(Activity::query()->sole()->error_code)->toBe('deployment.cancelled');
});

it('streams rollback with its release input and rollback phase', function (): void {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->call(
            'POST',
            "/api/v1/instances/{$this->fixture->instance->id}/rollback",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"release":"initial"}',
        );
    $events = array_map(
        static fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", $response->streamedContent()))),
    );

    expect($events[0])
        ->toMatchArray(['type' => 'phase', 'phase' => 'rollback'])
        ->and($events[array_key_last($events)])
        ->toMatchArray(['type' => 'result', 'status' => 'succeeded', 'selected_release' => 'initial'])
        ->and($this->fixture->deployment->requestedRelease)
        ->toBe('initial')
        ->and(Activity::query()->sole()->refresh()->properties?->get('input'))
        ->toBe(['release' => 'initial']);
});

it('streams and records rollback Process restart results on the rollback release', function (bool $fails): void {
    $runtime = new ProcessesApiFakeRuntimeManager;
    if ($fails) {
        $runtime->restartFailure = new ProcessOperationException('restart', 'process.restart_failed', 'private runtime output');
    }
    app()->instance(ProcessRuntimeManager::class, $runtime);
    $process = Process::query()->create([
        'owner_type' => $this->fixture->instance->getMorphClass(), 'owner_id' => $this->fixture->instance->id, 'name' => 'horizon',
        'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'horizon']],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
    ]);
    $this->fixture->deployment->current = 'fresh';

    $response = $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->call('POST', $this->url, server: ['CONTENT_TYPE' => 'application/json'], content: '{"release":"initial"}');
    $events = collect(explode("\n", $response->streamedContent()))->filter(fn (string $line): bool => trim($line) !== '')
        ->map(fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR))->values();
    $deployment = InstanceDeployment::query()->sole();

    expect($runtime->restarted)->toBe([$process->id])
        ->and($this->fixture->deployment->current)->toBe('initial')
        ->and($events->pluck('type')->all())->toBe(['phase', 'phase', 'output', 'result'])
        ->and($events[1]['phase'])->toBe('process_restart')
        ->and(base64_decode($events[2]['data_base64'], strict: true))->toBe($fails
            ? "Failed to restart Process {$process->id}: process.restart_failed.\n"
            : "Restarted Process {$process->id}.\n")
        ->and($events[3]['status'])->toBe($fails ? 'failed' : 'succeeded')
        ->and($events[3]['failed_step'])->toBe($fails ? 'after_activation' : null)
        ->and($events[3]['selected_release'])->toBe('initial')
        ->and($deployment->status)->toBe($fails ? 'failed' : 'succeeded')
        ->and($deployment->error_code)->toBe($fails ? 'process.restart_failed' : null)
        ->and(collect($deployment->events)->where('step', 'process_restart'))->toHaveCount(1);
})->with([false, true]);

it('finishes rollback PHP refresh and Process restart after the client disconnects during activation', function (): void {
    $runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $runtime);
    $php = Mockery::mock(ProductionPhpRuntimeManager::class);
    $php->shouldReceive('converge')->once();
    $php->shouldReceive('refreshCache')->once();
    app()->instance(ProductionPhpRuntimeManager::class, $php);
    $process = Process::query()->create([
        'owner_type' => $this->fixture->instance->getMorphClass(), 'owner_id' => $this->fixture->instance->id, 'name' => 'horizon',
        'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'horizon']],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
    ]);
    $this->fixture->deployment->current = 'fresh';
    $this->fixture->deployment->duringActivation = function (): void {
        $this->fixture->connection->disconnected = true;
    };

    $response = $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->call('POST', $this->url, server: ['CONTENT_TYPE' => 'application/json'], content: '{"release":"initial"}');
    $content = $response->streamedContent();
    $deployment = InstanceDeployment::query()->sole();

    expect($runtime->restarted)->toBe([$process->id])
        ->and($this->fixture->deployment->current)->toBe('initial')
        ->and($content)->not->toContain('"type":"result"')
        ->and($deployment->status)->toBe('succeeded')
        ->and($deployment->error_code)->toBeNull()
        ->and(collect($deployment->events)->where('step', 'process_restart'))->toHaveCount(1);
});
