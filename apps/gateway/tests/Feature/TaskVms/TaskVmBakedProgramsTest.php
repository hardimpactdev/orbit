<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\TaskVms\TaskVmBakedPrograms;
use App\Infrastructure\Tools\ComposerToolManager;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->node = Node::query()->create([
        'name' => 'tvm-12', 'status' => LifecycleStatus::Provisioning, 'platform' => 'linux',
        'public_ssh_host' => '10.251.77.12', 'wireguard_ip' => '10.44.0.140', 'user' => 'orbit',
    ]);
    $this->program = new RemoteCommand(['sudo', 'bash', '-seu', '--', 'orbit'], "apt-get update\n");
    $this->baked = app(TaskVmBakedPrograms::class);
    Log::spy();
});

describe('the gate', function (): void {
    it('holds a program that the image ran with the same fingerprint, and logs the skip', function (): void {
        $this->baked->load(new TaskVm(['name' => 'tvm-12']), TaskVmBakedPrograms::manifest(['bootstrap' => $this->program]));

        expect($this->baked->holds($this->node, 'bootstrap', new RemoteCommand(['sudo', 'bash', '-seu', '--', 'orbit'], "apt-get update\n")))->toBeTrue();
        Log::shouldHaveReceived('info')->with('Task VM program skipped: its image ran it.', ['task_vm' => 'tvm-12', 'program' => 'bootstrap']);
    });

    it('runs a program the image ran in another form, or did not run, and logs a warning', function (string $name, RemoteCommand $command, string $reason): void {
        $this->baked->load(new TaskVm(['name' => 'tvm-12']), TaskVmBakedPrograms::manifest(['bootstrap' => $this->program]));

        expect($this->baked->holds($this->node, $name, $command))->toBeFalse();
        Log::shouldHaveReceived('warning')->with('Task VM image did not run this program; enrollment runs it.', ['task_vm' => 'tvm-12', 'program' => $name, 'reason' => $reason]);
    })->with([
        'changed input' => ['bootstrap', new RemoteCommand(['sudo', 'bash', '-seu', '--', 'orbit'], "apt-get update -q\n"), 'the program changed since the image build'],
        'changed arguments' => ['bootstrap', new RemoteCommand(['sudo', 'bash', '-seu', '--', 'nckrtl'], "apt-get update\n"), 'the program changed since the image build'],
        'not in the manifest' => ['composer-manager', new RemoteCommand(['sudo', 'bash', '-seu', '--', 'orbit'], "apt-get update\n"), 'not in the image manifest'],
    ]);

    it('runs every program when the image has no usable manifest, and says so once', function (?string $manifest, string $reason): void {
        $this->baked->load(new TaskVm(['name' => 'tvm-12']), $manifest);

        expect($this->baked->holds($this->node, 'bootstrap', $this->program))->toBeFalse();
        Log::shouldHaveReceived('warning')->once()->with('Task VM image has no usable manifest; enrollment runs every program.', ['task_vm' => 'tvm-12', 'reason' => $reason]);
    })->with([
        'missing (an image built before manifests)' => [null, 'missing'],
        'not JSON' => ['{', 'unreadable'],
        'another schema' => ['{"schema":2,"programs":{}}', 'unreadable'],
        'an invalid fingerprint' => ['{"schema":1,"programs":{"bootstrap":"abc"}}', 'unreadable'],
        'an invalid name' => ['{"schema":1,"programs":{"Boot Strap":"'.str_repeat('a', 64).'"}}', 'unreadable'],
    ]);

    it('holds nothing for another Node, after stop, or for protected input', function (): void {
        $protected = new RemoteCommand(['sudo', 'tee', '/x'], protectedInput: ProtectedInput::fromString('secret'));
        $this->baked->load(new TaskVm(['name' => 'tvm-12']), TaskVmBakedPrograms::manifest(['bootstrap' => $this->program, 'secret' => $protected]));
        $other = new Node(['name' => 'tvm-13']);

        expect($this->baked->holds($other, 'bootstrap', $this->program))->toBeFalse()
            ->and(json_decode(TaskVmBakedPrograms::manifest(['secret' => $protected]), true)['programs'])->toBe([])
            ->and($this->baked->holds($this->node, 'secret', $protected))->toBeFalse();

        $this->baked->stop();

        expect($this->baked->holds($this->node, 'bootstrap', $this->program))->toBeFalse();
    });

    it('starts empty in every job', function (): void {
        $this->baked->load(new TaskVm(['name' => 'tvm-12']), TaskVmBakedPrograms::manifest(['bootstrap' => $this->program]));
        app()->forgetScopedInstances();

        expect(app(TaskVmBakedPrograms::class)->holds($this->node, 'bootstrap', $this->program))->toBeFalse();
    });
});

describe('tool managers', function (): void {
    it('skip the materialize program that the image ran, and run it otherwise', function (string $manager, string $name): void {
        $ssh = new class implements SshExecutor
        {
            /** @var list<RemoteCommand> */
            public array $commands = [];

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                $this->commands[] = $command;

                return new CommandResult(0, '', '', 1, false);
            }
        };
        app()->instance(SshExecutor::class, $ssh);
        $tools = app($manager);

        $tools->materialize($this->node);
        $this->baked->load(new TaskVm(['name' => 'tvm-12']), TaskVmBakedPrograms::manifest([$name => $tools->materializeCommand($this->node)]));
        $tools->materialize($this->node);

        expect($ssh->commands)->toHaveCount(1)
            ->and($ssh->commands[0]->arguments)->toBe(['sudo', 'bash', '-seu', '--', 'orbit'])
            ->and($ssh->commands[0]->input)->toBe($tools->materializeCommand($this->node)->input);
    })->with([
        'Vite+' => [VpToolManager::class, TaskVmBakedPrograms::VpManager],
        'Composer' => [ComposerToolManager::class, TaskVmBakedPrograms::ComposerManager],
    ]);
});
