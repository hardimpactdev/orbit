<?php

declare(strict_types=1);

use App\Domain\Operations\OperationSecrets;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Schedule;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'runtime-secrets', 'platform' => 'linux', 'status' => 'active',
        'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '192.0.2.9']);
    $this->process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id,
        'name' => 'secret-proof', 'runtime' => 'docker', 'restart_policy' => 'never', 'desired_state' => 'stopped', 'status' => 'active',
        'runtime_config' => ['image' => 'example:1', 'command' => ['sleep', '300'], 'environment' => ['PASSWORD' => 'owned-runtime-value']]]);
    $target = app(ProcessTargetResolver::class)->forInstallation($this->process);
    $this->operation = new Operation(['kind' => 'process.create', 'target_type' => 'process', 'target_id' => $this->process->id,
        'node_id' => $this->node->id, 'input' => ['process_id' => $this->process->id, 'runtime' => 'docker',
            'spec' => app(DockerProcessRenderer::class)->specHash($this->process, $target)]]);
    $this->secrets = app(OperationSecrets::class);
});

it('hydrates only an admitted installation reference with masks for individual values', function (): void {
    $input = ['installation' => ['$secret' => 'process.installation', 'id' => $this->process->id]];
    $hydrated = $this->secrets->hydrate($this->operation, 'install', $input, ['installation' => 'process.installation']);
    expect($hydrated['input']['installation']['definition']['environment'])->toBe(['PASSWORD' => 'owned-runtime-value']);
    expect($hydrated['values'])->toContain('owned-runtime-value');
    expect($this->secrets->redact(['echo' => 'owned-runtime-value'], $hydrated['values']))->toBe(['echo' => '[REDACTED]']);
    expect($input['installation'])->toBe(['$secret' => 'process.installation', 'id' => $this->process->id]);
});

it('refuses another target type and a Process changed after admission', function (): void {
    $input = ['installation' => ['$secret' => 'process.installation', 'id' => $this->process->id]];
    $this->operation->target_type = 'node';
    expect(fn () => $this->secrets->hydrate($this->operation, 'install', $input, ['installation' => 'process.installation']))->toThrow(ResourceOperationException::class);
    $this->operation->target_type = 'process';
    $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'environment' => ['PASSWORD' => 'changed-runtime-value']]]);
    expect(fn () => $this->secrets->hydrate($this->operation, 'install', $input, ['installation' => 'process.installation']))->toThrow(ResourceOperationException::class);
});

it('preserves an empty environment as an object through protected hydration', function (): void {
    $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'environment' => []]]);
    $this->operation->input = ['process_id' => $this->process->id, 'runtime' => 'docker',
        'spec' => app(DockerProcessRenderer::class)->specHash($this->process, app(ProcessTargetResolver::class)->forInstallation($this->process))];
    $hydrated = $this->secrets->hydrate($this->operation, 'install', ['installation' => ['$secret' => 'process.installation', 'id' => $this->process->id]], ['installation' => 'process.installation']);
    expect($hydrated['input']['installation']['definition']['environment'])->toBeObject();
});

it('binds a Schedule UUID to its actual numeric owner and recorded Node', function (): void {
    $schedule = Schedule::query()->create(['target_type' => Node::class, 'target_id' => $this->node->id, 'host_node_id' => $this->node->id,
        'name' => 'schedule-proof', 'command' => 'printf schedule-private-value', 'calendar' => 'daily', 'timeout_seconds' => 60,
        'desired_timer_state' => 'enabled', 'status' => 'active']);
    $operation = new Operation(['kind' => 'schedule.create', 'target_type' => 'node_schedules', 'target_id' => $this->node->id,
        'node_id' => $this->node->id, 'input' => ['id' => $schedule->id, 'command_hash' => hash('sha256', $schedule->command)]]);
    $input = ['command' => ['$secret' => 'schedule.command', 'id' => $this->node->id]];
    expect($this->secrets->hydrate($operation, 'install', $input, ['command' => 'schedule.command'])['input']['command'])->toBe($schedule->command);
    $operation->target_type = 'instance_schedules';
    expect(fn () => $this->secrets->hydrate($operation, 'install', $input, ['command' => 'schedule.command']))->toThrow(ResourceOperationException::class);
    $operation->target_type = 'node_schedules';
    $operation->node_id = $this->node->id + 1;
    expect(fn () => $this->secrets->hydrate($operation, 'install', $input, ['command' => 'schedule.command']))->toThrow(ResourceOperationException::class);
    $operation->node_id = $this->node->id;
    $schedule->update(['command' => 'printf changed-command']);
    expect(fn () => $this->secrets->hydrate($operation, 'install', $input, ['command' => 'schedule.command']))->toThrow(ResourceOperationException::class);
});

it('hydrates the whole admitted Docker installation without putting private bytes in its reference', function (string $kind): void {
    $this->operation->kind = $kind;
    $this->operation->input = [...$this->operation->input, 'runtime' => 'docker'];
    $input = ['installation' => ['$secret' => 'process.installation', 'id' => $this->process->id]];
    $hydrated = $this->secrets->hydrate($this->operation, '', $input, ['installation' => 'process.installation']);
    expect($hydrated['input']['installation'])->toBe([
        'runtime' => 'docker', 'spec' => $this->operation->input['spec'],
        'definition' => app(DockerProcessRenderer::class)->containerInput($this->process, app(ProcessTargetResolver::class)->forInstallation($this->process)),
    ])->and($hydrated['values'])->toContain('owned-runtime-value')
        ->and(json_encode($input))->not->toContain('owned-runtime-value');
})->with(['process.create', 'process.update']);

it('refuses private installation material for another owner Node runtime or changed definition', function (): void {
    $original = [...$this->operation->input, 'runtime' => 'docker'];
    $input = ['installation' => ['$secret' => 'process.installation', 'id' => $this->process->id]];
    foreach (['node', 'owner', 'runtime', 'spec'] as $variant) {
        $this->operation->target_id = $this->process->id;
        $this->operation->node_id = $this->node->id;
        $this->operation->input = $original;
        match ($variant) {
            'node' => $this->operation->node_id = $this->node->id + 1,
            'owner' => $this->operation->target_id = $this->process->id + 1,
            'runtime' => $this->operation->input = [...$original, 'runtime' => 'systemd'],
            'spec' => $this->operation->input = [...$original, 'spec' => str_repeat('a', 64)],
        };
        expect(fn () => $this->secrets->hydrate($this->operation, '', $input, ['installation' => 'process.installation']))->toThrow(ResourceOperationException::class);
    }
});

it('binds private unit installation to its rendered digest and installing kind', function (): void {
    $this->process->update(['runtime' => 'systemd', 'working_directory' => '/home/orbit', 'runtime_config' => [
        'command' => ['/usr/bin/true'], 'environment_file' => '', 'environment' => ['PASSWORD' => 'private-unit-value'],
    ]]);
    $target = app(ProcessTargetResolver::class)->forInstallation($this->process);
    $content = app(SystemdProcessRenderer::class)->render($this->process, $target);
    $this->operation->input = ['process_id' => $this->process->id, 'runtime' => 'systemd', 'content_hash' => hash('sha256', $content)];
    $input = ['installation' => ['$secret' => 'process.installation', 'id' => $this->process->id]];
    $resolved = $this->secrets->hydrate($this->operation, '', $input, ['installation' => 'process.installation']);
    expect($resolved['input']['installation'])->toBe(['runtime' => 'systemd', 'content' => $content])
        ->and($resolved['values'])->toContain('private-unit-value');
    $this->operation->kind = 'process.start';
    expect(fn () => $this->secrets->hydrate($this->operation, '', $input, ['installation' => 'process.installation']))->toThrow(ResourceOperationException::class);
    $this->operation->kind = 'process.create';
    $this->operation->input = [...$this->operation->input, 'content_hash' => str_repeat('a', 64)];
    expect(fn () => $this->secrets->hydrate($this->operation, '', $input, ['installation' => 'process.installation']))->toThrow(ResourceOperationException::class);
});
