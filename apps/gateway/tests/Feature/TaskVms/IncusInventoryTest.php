<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\TaskVms\IncusHost;
use App\Infrastructure\TaskVms\IncusTaskVmBaseImage;
use App\Infrastructure\TaskVms\IncusTaskVmProvider;
use App\Infrastructure\TaskVms\TaskVmVolumes;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\TaskVmSettingsBuilder;

it('reads image, instance, and host inventories through the agent on the selected Node', function (): void {
    $node = new Node(['id' => 123, 'name' => 'host']);
    $host = TaskVmSettingsBuilder::host(123);
    $fingerprint = str_repeat('a', 64);
    $inventory = ['instances' => [['project' => 'default', 'name' => 'operator-vm']], 'volumes' => [['project' => 'orbit-tasks', 'pool' => 'tank', 'name' => 'data']]];
    $agents = new FakeAgentCommandRunner;
    $agents->answer('incus.images', ['images' => [$fingerprint => ['orbit-task-base']]])
        ->answer('incus.instances', ['names' => ['tvm-1']])
        ->answer('incus.inventory', $inventory);
    app()->instance(AgentCommandRunner::class, $agents);
    $incus = app(IncusHost::class);

    expect($incus->images($host, $node, 37))->toBe([$fingerprint => ['orbit-task-base']])
        ->and($incus->instanceNames($host, $node))->toBe(['tvm-1'])
        ->and($incus->inventory($node))->toBe($inventory)
        ->and($incus->aliasTarget($host, $node, 'orbit-task-base'))->toBe($fingerprint)
        ->and($incus->aliasTarget($host, $node, 'absent'))->toBeNull();
    expect(array_slice($agents->calls, 0, 3))->toBe([
        ['node' => 'host', 'command' => 'incus.images', 'input' => ['project' => $host->project]],
        ['node' => 'host', 'command' => 'incus.instances', 'input' => ['project' => $host->project]],
        ['node' => 'host', 'command' => 'incus.inventory', 'input' => []],
    ])->and($agents->deadlines[0])->toBe(37.0);
});

it('maps native inventory refusals to the existing host error', function (string $code, array $details, string $message): void {
    $node = new Node(['id' => 123, 'name' => 'host']);
    $agents = new FakeAgentCommandRunner;
    $agents->refuse('incus.instances', $code, $details);
    app()->instance(AgentCommandRunner::class, $agents);

    expect(fn () => app(IncusHost::class)->instanceNames(TaskVmSettingsBuilder::host(123), $node))
        ->toThrow(fn (TaskVmException $error) => expect([$error->errorCode, $error->status, $error->getMessage()])->toBe([$code, 502, $message]));
    expect($agents->commands())->toBe(['incus.instances']);
})->with([
    'unreadable' => ['task_vm.invalid_host_output', ['reason' => 'the output is not complete'], 'Incus returned invalid output for the instances of Node [host]: the output is not complete.'],
    'failed' => ['task_vm.host_command_failed', ['operation' => 'project show', 'exit_code' => 1, 'stderr' => 'Project not found'], "`incus project show` for the instances of Node [host] failed with exit code [1].\nProject not found"],
]);

it('rejects malformed typed inventory results', function (string $method, string $command, array $output): void {
    $node = new Node(['id' => 123, 'name' => 'host']);
    $agents = new FakeAgentCommandRunner;
    $agents->answer($command, $output);
    app()->instance(AgentCommandRunner::class, $agents);
    $incus = app(IncusHost::class);

    expect(fn () => $method === 'inventory' ? $incus->inventory($node) : $incus->{$method}(TaskVmSettingsBuilder::host(123), $node))
        ->toThrow(TaskVmException::class);
})->with([
    ['images', 'incus.images', []],
    ['images', 'incus.images', ['images' => ['pin' => [42]]]],
    ['instanceNames', 'incus.instances', ['names' => ['name' => 'value']]],
    ['inventory', 'incus.inventory', []],
    ['inventory', 'incus.inventory', ['instances' => [['project' => null, 'name' => 'x']], 'volumes' => []]],
    ['inventory', 'incus.inventory', ['instances' => [], 'volumes' => [['project' => 'p', 'pool' => 42, 'name' => 'x']]]],
]);

it('keeps migrated Incus inventory methods behind the typed agent boundary', function (): void {
    foreach (['Infrastructure/TaskVms/IncusHost.php', 'Infrastructure/TaskVms/IncusTaskVmBaseImage.php', 'Infrastructure/TaskVms/IncusGuestShell.php', 'Infrastructure/TaskVms/TaskVmHostAudit.php', 'Infrastructure/TaskVms/IncusTaskVmImageBuilder.php', 'Infrastructure/TaskVms/RecipeImagePruning.php', 'Infrastructure/TaskVms/TaskVmVolumes.php', 'Infrastructure/TaskVms/TaskVmHubFilter.php', 'Console/Commands/PrepareTaskVmHubCommand.php'] as $path) {
        $source = file_get_contents(app_path($path));
        foreach (['SshExecutor', 'RemoteCommand', 'TaskVmScripts', 'Process::', 'shell_exec', 'bash'] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }
    }
    $lines = file(app_path('Infrastructure/TaskVms/IncusHost.php'));
    foreach (['images', 'instanceNames', 'inventory', 'observe', 'volumeNames', 'volume', 'prepare', 'vmState', 'changeVm', 'instanceImages', 'image'] as $name) {
        $method = new ReflectionMethod(IncusHost::class, $name);
        $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        foreach (['RemoteCommand', '$this->execute(', '$this->run(', 'json_decode', 'parse_url', 'parse_str'] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }
    }
});

it('finishes and removes only the topology volumes selected by the Gateway', function (): void {
    $node = new Node(['id' => 123, 'name' => 'host']);
    $host = TaskVmSettingsBuilder::host(123);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('incus.volume', ['name' => 'orbit-topology-seed-aaaaaaaaaaaa']);
    $agents->answer('incus.volumes', ['names' => ['orbit-topology-seed-aaaaaaaaaaaa']]);
    app()->instance(AgentCommandRunner::class, $agents);
    $volumes = app(TaskVmVolumes::class);

    $volumes->finishSeed($host, $node, str_repeat('a', 64));
    expect($volumes->exists($host, $node, 'orbit-topology-seed-aaaaaaaaaaaa'))->toBeTrue();
    $volumes->delete($host, $node, 'orbit-topology-seed-aaaaaaaaaaaa');

    expect(array_column($agents->calls, 'input'))->toBe([
        ['location' => ['project' => $host->project, 'pool' => $host->pool], 'operation' => ['kind' => 'finish_seed', 'fingerprint' => str_repeat('a', 64)]],
        ['project' => $host->project, 'pool' => $host->pool],
        ['location' => ['project' => $host->project, 'pool' => $host->pool], 'operation' => ['kind' => 'remove', 'name' => 'orbit-topology-seed-aaaaaaaaaaaa']],
    ]);
});

it('keeps VM lifecycle decisions behind the native command boundary', function (): void {
    $lines = file(app_path('Infrastructure/TaskVms/IncusTaskVmProvider.php'));
    foreach (['create', 'observe', 'start', 'stop', 'destroy', 'change'] as $name) {
        $method = new ReflectionMethod(IncusTaskVmProvider::class, $name);
        $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        foreach (['RemoteCommand', '$this->incus(', 'json_decode', '$this->observe(', '$this->incus->run(', '$this->incus->launch('] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }
    }
});

it('sends image builder and smoke intent without executable arguments or cloud-init source', function (bool $builder): void {
    $node = new Node(['id' => 123, 'name' => 'host']);
    $host = TaskVmSettingsBuilder::host(123);
    $agents = (new FakeAgentCommandRunner)->answer('incus.vm', ['ready' => true]);
    app()->instance(AgentCommandRunner::class, $agents);
    $images = app(IncusTaskVmBaseImage::class);

    $images->launch($host, $node, 'tvm-image-9', 'stock-image', 'ssh-ed25519 AAAA gateway', $builder);
    $images->delete($host, $node, 'tvm-image-9');

    expect(array_column($agents->calls, 'input'))->toBe([
        ['vm' => ['project' => $host->project, 'name' => 'tvm-image-9', 'cidr' => $host->cidr], 'operation' => [
            'kind' => 'create_image', 'image' => 'stock-image', 'gateway_public_key' => 'ssh-ed25519 AAAA gateway',
            'cpus' => $host->cpus, 'memory' => $host->memory, 'disk' => $host->disk, 'network' => $host->network, 'builder' => $builder,
        ]],
        ['vm' => ['project' => $host->project, 'name' => 'tvm-image-9', 'cidr' => $host->cidr], 'operation' => ['kind' => 'remove_instance']],
    ]);
})->with([true, false]);
