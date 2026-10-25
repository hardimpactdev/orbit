<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GatewayReleases\GatewayReleaseDrain;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseExecutionOwner;
use App\Domain\Processes\ProcessTarget;
use App\Infrastructure\GatewayReleases\GatewayReleaseProcessDeclaration;
use App\Infrastructure\GatewayReleases\SystemdGatewayReleaseDrain;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\GatewayReleasePipeline;

beforeEach(function (): void {
    $this->pipeline = new GatewayReleasePipeline(adopted: true);
    $this->source = $this->pipeline->adopt();
    $this->target = $this->pipeline->fixture->commit('Staged Process continuity');
    $this->pipeline->bindLeaves();
    $this->ordinary = '/home/orbit/ordinary';
    $this->node = Node::query()->create(['name' => 'declaration-test', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '127.0.0.1', 'user' => 'orbit']);
    app(GatewayServingHost::class)->remember($this->node);
    $this->process = Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'ordinary-native',
        'runtime' => 'systemd', 'working_directory' => $this->ordinary,
        'runtime_config' => ['command' => ['/usr/bin/sleep', 'infinity'], 'environment' => []],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
    ]);
    $renderer = new SystemdProcessRenderer;
    $this->unit = $renderer->unitName($this->process);
    $this->rendered = $renderer->render($this->process, new ProcessTarget($this->node, 'orbit', $this->ordinary));
    $this->agents = new FakeAgentCommandRunner;
    $this->identity = ['source_sha' => $this->source, 'unit_sha256' => hash('sha256', $this->rendered), 'runtime_sha256' => str_repeat('c', 64)];
    $this->agents->answer('gateway.inspect-release-process', fn (): array => $this->identity);
    $this->hold = ['unrelated_processes' => []];
    $this->agents->answer('gateway.inspect-release-hold', fn (): array => $this->hold);
    $this->declarations = new GatewayReleaseProcessDeclaration($this->agents, $this->pipeline->fixture->layout, app(GatewayServingHost::class));
    app()->instance(GatewayReleaseProcessDeclaration::class, $this->declarations);
    $this->drain = new SystemdGatewayReleaseDrain(
        $this->pipeline->fixture->releaseCommands($this->agents), $this->pipeline->fixture->layout, $this->declarations,
    );
    app()->instance(GatewayReleaseDrain::class, $this->drain);
    config(['queue.connections.gateway.driver' => 'redis', 'queue.connections.gateway.connection' => 'gateway']);
    $this->redis = Mockery::mock();
    Redis::shouldReceive('connection')->with('gateway')->andReturn($this->redis);
    $this->redis->shouldReceive('zcard')->andReturn(0)->byDefault();
    $this->declaration = function (): array {
        $code = Artisan::call('gateway:release:inspect-process', ['commit' => $this->target, 'process' => (string) $this->process->id]);
        $output = Artisan::output();
        expect($code)->toBe(0, $output);
        expect($output)->not->toContain('do-not-report');

        return json_decode($output, true)['observation'];
    };
});

afterEach(function (): void {
    $this->pipeline->cleanup();
});

it('observes native identity with exact record intent and leaves product state unchanged', function (): void {
    $before = $this->process->fresh()->getAttributes();

    $observation = ($this->declaration)();

    expect($observation)->toMatchArray([
        'process_id' => $this->process->id, 'node_id' => $this->node->id, 'unit' => $this->unit,
        'source_sha' => $this->source, 'target_sha' => $this->target,
        'unit_sha256' => $this->identity['unit_sha256'], 'runtime_sha256' => $this->identity['runtime_sha256'],
    ]);
    expect($observation['spec_sha256'])->toHaveLength(64);
    expect($this->agents->calls)->toBe([[
        'node' => $this->node->name, 'command' => 'gateway.inspect-release-process',
        'input' => [
            'checkout' => $this->pipeline->fixture->layout->applicationPath, 'orbit_home' => $this->pipeline->home,
            'target_sha' => $this->target, 'process_id' => $this->process->id, 'unit' => $this->unit,
            'user' => 'orbit', 'directory' => $this->ordinary,
            'command' => ['/usr/bin/sleep', 'infinity'], 'rendered' => $this->rendered,
        ],
    ]]);
    expect($this->process->fresh()->getAttributes())->toBe($before);
    expect(GatewayRelease::query()->count())->toBe(0);
    expect(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeFalse();
});

it('stages after rechecking the complete unrelated Process declaration', function (): void {
    $declaration = ($this->declaration)();
    $this->hold['unrelated_processes'] = [array_reverse($declaration, true)];
    $before = $this->process->fresh()->getAttributes();

    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(0, Artisan::output());
    expect(GatewayRelease::query()->sole()->outcome)->toBe('paused');
    expect($this->process->fresh()->getAttributes())->toBe($before);
    expect($this->agents->commands())->toContain('gateway.inspect-release-hold', 'gateway.inspect-release-process');
});

it('refuses a stale or duplicated declaration before recording a stage', function (string $fault): void {
    $declaration = ($this->declaration)();
    if ($fault === 'duplicate') {
        $this->hold['unrelated_processes'] = [$declaration, $declaration];
    } else {
        $declaration[$fault] = match ($fault) {
            'process_id', 'node_id' => 999999,
            'unit' => 'php8.5-fpm.service', 'extra' => 'unreviewed',
            default => str_repeat('b', str_ends_with($fault, '_sha256') ? 64 : 40),
        };
        $this->hold['unrelated_processes'] = [$declaration];
    }

    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_not_drained');
    expect(GatewayRelease::query()->count())->toBe(0);
})->with(['source_sha', 'target_sha', 'spec_sha256', 'unit_sha256', 'runtime_sha256', 'process_id', 'node_id', 'unit', 'extra', 'duplicate']);

it('refuses ineligible record policy before native observation', function (string $fault): void {
    match ($fault) {
        'unknown-owner' => $this->process->update(['owner_type' => 'node']),
        'placement' => $this->process->update(['owner_id' => 999999]),
        'serving-node' => app(GatewayServingHost::class)->remember(new Node()->forceFill(['id' => 999999])),
        'preset' => $this->process->update(['runtime_config' => ['command' => ['/usr/bin/sleep', 'infinity'], 'preset' => 'vp-dev']]),
        'environment' => $this->process->update(['runtime_config' => ['command' => ['/usr/bin/sleep'], 'environment' => ['SECRET' => 'do-not-report']]]),
        'pending' => $this->process->forceFill(['systemd_activation_pending' => true])->save(),
        'activation' => $this->process->forceFill(['systemd_activation_hash' => str_repeat('f', 64)])->save(),
        'stopped' => $this->process->update(['desired_state' => 'stopped']),
        'horizon' => $this->process->update(['runtime_config' => ['command' => ['/usr/bin/php8.5', 'artisan', 'horizon']]]),
    };

    expect(fn () => $this->declarations->observe($this->target, $this->process->id))->toThrow(GatewayReleaseException::class);
    expect($this->agents->calls)->toBe([]);
})->with(['unknown-owner', 'placement', 'serving-node', 'preset', 'environment', 'pending', 'activation', 'stopped', 'horizon']);

it('refuses a changed record specification even when native hashes match', function (): void {
    $this->hold['unrelated_processes'] = [($this->declaration)()];
    $this->process->update(['keep_alive' => ! $this->process->keep_alive]);

    expect(fn () => $this->drain->assertHeld($this->target))->toThrow(GatewayReleaseException::class, 'stale or duplicated');
});

it('refuses an invalid target before native observation', function (): void {
    expect(fn () => $this->declarations->observe('main', $this->process->id))->toThrow(GatewayReleaseException::class, 'exact target SHA');
    expect($this->agents->calls)->toBe([]);
});

it('rejects incomplete native identities', function (string $field, mixed $value): void {
    $this->identity[$field] = $value;
    $this->agents->answerMalformed('gateway.inspect-release-process', fn (): array => $this->identity);

    expect(fn () => $this->declarations->observe($this->target, $this->process->id))->toThrow(GatewayReleaseException::class, 'incomplete Process identity');
})->with([
    ['source_sha', null], ['source_sha', 'not-a-sha'],
    ['unit_sha256', null], ['unit_sha256', str_repeat('f', 64)],
    ['runtime_sha256', null], ['runtime_sha256', str_repeat('g', 64)],
]);

it('redacts native identity failures at the CLI boundary', function (): void {
    $this->agents->answer('gateway.inspect-release-process', static fn (): array => throw new RuntimeException('secret=do-not-report'));

    expect(Artisan::call('gateway:release:inspect-process', ['commit' => $this->target, 'process' => (string) $this->process->id]))->toBe(1);
    $output = Artisan::output();
    expect(json_decode($output, true)['error_code'])->toBe('gateway.release_not_drained');
    expect($output)->not->toContain('do-not-report');
});

it('validates the execution owner before observing a Process', function (): void {
    app()->instance(GatewayReleaseExecutionOwner::class, new class implements GatewayReleaseExecutionOwner
    {
        public function assertAllowed(?string $sha = null): void
        {
            throw new GatewayReleaseException('owner', 'gateway.release_owner_invalid', 'wrong account');
        }
    });

    expect(Artisan::call('gateway:release:inspect-process', ['commit' => $this->target, 'process' => (string) $this->process->id]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_owner_invalid');
    expect($this->agents->calls)->toBe([]);
});

it('keeps native writer and queue fences after declaring an unrelated Process', function (string $fault): void {
    $this->hold['unrelated_processes'] = [($this->declaration)()];
    if ($fault === 'reservation') {
        $this->redis->shouldReceive('zcard')->with('queues:tasks:reserved')->andReturn(1);
    } else {
        $this->agents->refuse('gateway.inspect-release-hold', 'gateway.release_not_drained', ['stage' => 'writer']);
    }

    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_not_drained');
    expect(GatewayRelease::query()->count())->toBe(0);
})->with(['reservation', 'native-writer']);
