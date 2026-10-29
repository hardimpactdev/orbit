<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Logs\LogReadLimit;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleDependencySet;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\VpDevPreset;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Nodes\NativeNodeRoleDependentCleaner;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\RemoteProcessRuntimeManager;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $this->agents = new FakeAgentCommandRunner;
    $this->manager = new RemoteProcessRuntimeManager(
        targets: new ProcessTargetResolver,
        accounts: new ProcessRuntimeFakeManagedUserAccountResolver,
        agents: $this->agents,
        systemd: new SystemdProcessRenderer,
        docker: new DockerProcessRenderer,
    );
    $node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $node->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);
    $orbitApp = Project::query()->create([
        'name' => 'Docs',
        'slug' => 'docs',
        'repository_url' => 'git@example.test:docs.git',
    ]);
    $this->instance = Instance::query()->create([
        'project_id' => $orbitApp->id,
        'node_id' => $node->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/docs',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
});

it('leaves an unchanged owned systemd unit untouched during convergence including legacy activation records', function (string $desired, string $runtimeStatus, bool $legacy): void {
    $process = runtime_manager_systemd_process($this->instance);
    $process->update(['desired_state' => $desired]);
    $rendered = runtime_manager_rendered_unit($process);
    if (! $legacy) {
        $process->systemd_activation_hash = hash('sha256', $rendered);
        $process->save();
    }
    $this->agents->answer('process.stage-unit', ['staged' => false, 'previous' => true, 'state' => $runtimeStatus]);

    $this->manager->converge($process);

    expect($this->agents->commands())->toBe(['process.stage-unit'])
        ->and($this->agents->calls[0]['input'])->toBe([
            'process_id' => $process->id,
            'unit' => "orbit-process-{$process->id}-queue.service",
            'content' => $rendered,
        ])
        ->and($this->agents->calls[0]['node'])->toBe('app-dev');
})->with([['running', 'active', false], ['stopped', 'inactive', false], ['running', 'active', true], ['stopped', 'inactive', true]]);

it('reactivates a published unit whose activation is pending or whose state differs', function (string $case): void {
    $process = runtime_manager_systemd_process($this->instance);
    $process->update(['desired_state' => 'running']);
    $rendered = runtime_manager_rendered_unit($process);
    $process->systemd_activation_hash = $case === 'other hash' ? hash('sha256', 'other') : hash('sha256', $rendered);
    $process->systemd_activation_pending = $case === 'pending';
    $process->save();
    $this->agents->answer('process.stage-unit', ['staged' => false, 'previous' => true, 'state' => $case === 'failed' ? 'failed' : 'active']);
    $this->agents->answer('process.activate-unit', ['backup_left' => false]);

    $this->manager->converge($process);

    expect($this->agents->commands())->toBe(['process.stage-unit', 'process.activate-unit'])
        ->and($this->agents->calls[1]['input']['change'])->toBe('reload')
        ->and($process->refresh()->systemd_activation_hash)->toBe(hash('sha256', $rendered))
        ->and($process->systemd_activation_pending)->toBeFalse();
})->with(['pending', 'other hash', 'failed']);

it('records a pending activation before publication and completes it on the next converge after a lost response', function (): void {
    $process = runtime_manager_systemd_process($this->instance);
    $process->update(['desired_state' => 'running']);
    $process->systemd_activation_hash = hash('sha256', 'previous');
    $process->save();
    $rendered = runtime_manager_rendered_unit($process);
    $this->agents->answer('process.stage-unit', ['staged' => true, 'previous' => true, 'state' => null]);
    $this->agents->answer('process.activate-unit', static function () use ($process): array {
        expect($process->refresh()->systemd_activation_pending)->toBeTrue()
            ->and($process->systemd_activation_hash)->toBeNull();

        throw new RuntimeException('Lost native response');
    });

    expect(fn () => $this->manager->converge($process))->toThrow(RuntimeException::class, 'Lost native response');
    expect($this->agents->calls[1]['input'])->toBe([
        'process_id' => $process->id,
        'unit' => "orbit-process-{$process->id}-queue.service",
        'change' => 'replace',
        'desired' => 'running',
        'on_demand' => true,
    ])->and($process->refresh()->systemd_activation_pending)->toBeTrue();

    $this->agents->calls = [];
    $this->agents->answer('process.stage-unit', ['staged' => false, 'previous' => true, 'state' => 'active']);
    $this->agents->answer('process.activate-unit', ['backup_left' => false]);
    $this->manager->converge($process);

    expect($this->agents->calls[1]['input']['change'])->toBe('reload')
        ->and($process->refresh()->systemd_activation_hash)->toBe(hash('sha256', $rendered))
        ->and($process->systemd_activation_pending)->toBeFalse();

    $this->agents->calls = [];
    $this->manager->converge($process);
    expect($this->agents->commands())->toBe(['process.stage-unit']);
});

it('installs and manages a systemd process through processes commands', function (): void {
    $process = runtime_manager_systemd_process($this->instance);
    $unit = "orbit-process-{$process->id}-queue.service";
    $this->agents
        ->answer('process.stage-unit', ['staged' => true, 'previous' => false, 'state' => null])
        ->answer('process.activate-unit', ['backup_left' => false])
        ->answer('process.control', [])
        ->answer('process.logs', ['output' => "2026-09-25T10:00:02+00:00 host queue[7]: two\n2026-09-25T10:00:01+00:00 host queue[7]: one\n"])
        ->answer('process.remove', []);

    $this->manager->converge($process);
    $this->manager->start($process);
    $this->manager->stop($process);
    $logs = $this->manager->logs($process, 50);
    $this->manager->remove($process);

    $artifact = ['process_id' => $process->id, 'runtime' => 'systemd', 'name' => $unit];
    expect($this->agents->commands())->toBe([
        'process.stage-unit',
        'process.activate-unit',
        'process.control',
        'process.control',
        'process.logs',
        'process.remove',
    ])
        ->and($this->agents->calls[0]['input']['content'])
        ->toContain("X-Orbit-Process-ID={$process->id}")
        ->toContain("Environment=VITE_DEV_SERVER_CERT=/home/orbit/.orbit/certificates/app-instance-{$this->instance->id}/current/cert.pem")
        ->and($this->agents->calls[1]['input'])->toBe(['process_id' => $process->id, 'unit' => $unit, 'change' => 'create', 'desired' => 'stopped', 'on_demand' => true])
        ->and($this->agents->calls[2]['input'])->toBe(['operation' => 'start', ...$artifact, 'on_demand' => true])
        ->and($this->agents->calls[3]['input'])->toBe(['operation' => 'stop', ...$artifact, 'on_demand' => true])
        ->and($this->agents->calls[4]['input'])->toBe([...$artifact, 'lines' => 50, 'max_bytes' => LogReadLimit::Bytes])
        ->and($this->agents->calls[5]['input'])->toBe([...$artifact, 'vite' => null])
        ->and($logs)->toBe("2026-09-25T10:00:01+00:00 host queue[7]: one\n2026-09-25T10:00:02+00:00 host queue[7]: two\n")
        ->and($process->refresh()->systemd_activation_hash)->toBe(hash('sha256', runtime_manager_rendered_unit($process)));
});

it('rejects leftover Workspace ownership before systemd convergence', function (): void {
    $process = runtime_manager_leftover_workspace_process();

    expect(fn () => $this->manager->converge($process))
        ->toThrow(ResourceOperationException::class, 'not a supported Instance or Node');

    expect($this->agents->calls)->toBeEmpty();
});

it('maps a unit that another owner holds to a name collision', function (): void {
    $process = runtime_manager_systemd_process($this->instance);
    $path = "/etc/systemd/system/orbit-process-{$process->id}-queue.service";
    $this->agents->refuse('process.stage-unit', 'process.name_collision', ['step' => 'inspect-unit', 'artifact' => $path, 'output' => ['exit_code' => 0, 'stdout' => "[Unit]\n", 'stderr' => '']]);

    expect(fn () => $this->manager->converge($process))->toThrow(function (ProcessOperationException $exception) use ($path): void {
        expect($exception->errorCode)->toBe('process.runtime_name_collision')
            ->and($exception->step)->toBe('inspect-unit')
            ->and($exception->getMessage())->toBe("Systemd unit [{$path}] is not owned by this process.")
            ->and($exception->result?->stdout)->toBe("[Unit]\n");
    });
});

it('keeps the activation record when the systemd candidate fails validation', function (): void {
    $process = runtime_manager_systemd_process($this->instance);
    $process->systemd_activation_hash = hash('sha256', 'previous');
    $process->save();
    $this->agents->refuse('process.stage-unit', 'process.candidate_invalid', ['step' => 'verify-unit', 'artifact' => 'unit', 'output' => ['exit_code' => 1, 'stdout' => '', 'stderr' => 'bad directive']]);

    expect(fn () => $this->manager->converge($process))->toThrow(function (ProcessOperationException $exception): void {
        expect($exception->errorCode)->toBe('process.systemd_candidate_invalid')
            ->and($exception->step)->toBe('verify-unit')
            ->and($exception->getMessage())->toContain('systemd candidate validation failed')
            ->and($exception->result?->stderr)->toBe('bad directive');
    });
    expect($process->refresh()->systemd_activation_hash)->toBe(hash('sha256', 'previous'))
        ->and($process->systemd_activation_pending)->toBeFalse()
        ->and($this->agents->commands())->toBe(['process.stage-unit']);
});

it('names what a failed systemd activation restored', function (bool $previous, string $desired, string $message): void {
    $process = runtime_manager_systemd_process($this->instance);
    $process->update(['desired_state' => $desired]);
    $this->agents->answer('process.stage-unit', ['staged' => true, 'previous' => $previous, 'state' => null]);
    $code = $desired === 'running' ? 'process.start_failed' : 'process.stop_failed';
    $this->agents->refuse('process.activate-unit', $code, ['step' => $desired === 'running' ? 'start' : 'stop', 'restored' => true, 'output' => ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Job failed']]);

    expect(fn () => $this->manager->converge($process))->toThrow(function (ProcessOperationException $exception) use ($process, $desired, $message): void {
        $state = $desired === 'running' ? 'start' : 'stop';
        expect($exception->errorCode)->toBe("process.{$state}_failed")
            ->and($exception->step)->toBe($state)
            ->and($exception->getMessage())->toBe("Process [{$process->name}] failed to {$state}; {$message}.");
    });
    expect($process->refresh()->systemd_activation_pending)->toBeTrue();
})->with([
    [true, 'running', 'the previous unit was restored'],
    [false, 'running', 'the new unit was removed'],
    [true, 'stopped', 'the previous unit was restored'],
]);

it('maps a failed reload after publication to an activation failure', function (): void {
    $process = runtime_manager_systemd_process($this->instance);
    $this->agents->answer('process.stage-unit', ['staged' => true, 'previous' => true, 'state' => null]);
    $this->agents->refuse('process.activate-unit', 'process.activation_failed', ['step' => 'daemon-reload', 'output' => ['exit_code' => 1, 'stdout' => '', 'stderr' => '']]);

    expect(fn () => $this->manager->converge($process))->toThrow(function (ProcessOperationException $exception) use ($process): void {
        expect($exception->errorCode)->toBe('process.systemd_activation_failed')
            ->and($exception->step)->toBe('daemon-reload')
            ->and($exception->getMessage())->toBe("Process [{$process->name}] systemd activation failed; the previous unit was restored.");
    });
});

it('maps systemd rollback and recovery failures', function (string $code, string $step, string $expected): void {
    $process = runtime_manager_systemd_process($this->instance);
    $this->agents->answer('process.stage-unit', ['staged' => true, 'previous' => true, 'state' => null]);
    $this->agents->refuse('process.activate-unit', $code, ['step' => $step, 'reason' => 'step_failed', 'output' => ['exit_code' => 1, 'stdout' => '', 'stderr' => '']]);

    expect(fn () => $this->manager->converge($process))->toThrow(function (ProcessOperationException $exception) use ($step, $expected): void {
        expect($exception->errorCode)->toBe($expected)->and($exception->step)->toBe($step);
    });
})->with([
    ['process.rollback_failed', 'restore-unit', 'process.systemd_rollback_failed'],
    ['process.rollback_failed', 'restore-service-state', 'process.systemd_rollback_failed'],
    ['process.recovery_required', 'stop-replacement-unit', 'process.systemd_recovery_required'],
]);

it('records the activation before it reports a backup that could not be deleted', function (): void {
    $process = runtime_manager_systemd_process($this->instance);
    $this->agents->answer('process.stage-unit', ['staged' => true, 'previous' => true, 'state' => null]);
    $this->agents->answer('process.activate-unit', ['backup_left' => true]);

    expect(fn () => $this->manager->converge($process))->toThrow(function (ProcessOperationException $exception): void {
        expect($exception->errorCode)->toBe('process.systemd_converge_failed')
            ->and($exception->step)->toBe('remove-unit-backup');
    });
    expect($process->refresh()->systemd_activation_hash)->toBe(hash('sha256', runtime_manager_rendered_unit($process)))
        ->and($process->systemd_activation_pending)->toBeFalse();
});

it('converges a Docker process with its typed definition in one command', function (): void {
    $process = runtime_manager_docker_process($this->instance, ['DB_PASSWORD' => 'secret-value', 'APP_NAME' => 'docs']);
    $process->update(['desired_state' => 'running']);
    $this->agents->answer('process.converge-container', []);

    $this->manager->converge($process);

    expect($this->agents->commands())->toBe(['process.converge-container'])
        ->and($this->agents->calls[0]['input'])->toBe([
            'process_id' => $process->id,
            'container' => "orbit-process-{$process->id}-redis",
            'desired' => 'running',
            'spec' => $this->manager->dockerSpecHash($process),
            'image' => 'redis:8-alpine',
            'command' => ['redis-server'],
            'restart' => 'unless-stopped',
            'working_directory' => '/data',
            'ports' => ['127.0.0.1:6380:6379/tcp'],
            'mounts' => [['source' => 'redis-data', 'target' => '/data', 'read_only' => false]],
            'environment' => ['APP_NAME' => 'docs', 'DB_PASSWORD' => 'secret-value'],
        ]);
});

it('sends an empty Docker environment as an object', function (): void {
    $process = runtime_manager_docker_process($this->instance);
    $this->agents->answer('process.converge-container', []);

    $this->manager->converge($process);

    expect(json_encode($this->agents->calls[0]['input']['environment']))->toBe('{}');
});

it('validates Docker rendering before any Node change', function (): void {
    $process = runtime_manager_docker_process($this->instance);
    $process->update(['runtime_config' => ['image' => 'bad image', 'command' => ['redis-server']]]);

    expect(fn () => $this->manager->converge($process))->toThrow(InvalidArgumentException::class);
    expect($this->agents->calls)->toBeEmpty();
});

it('maps Docker refusals to their codes and messages and redacts environment values', function (string $code, array $details, string $expectedCode, string $expectedMessage): void {
    $process = runtime_manager_docker_process($this->instance, ['DB_PASSWORD' => 'secret-value']);
    $name = "orbit-process-{$process->id}-redis";
    $details = array_map(static fn (mixed $value): mixed => is_string($value) ? str_replace('{name}', $name, $value) : $value, $details);
    $this->agents->refuse('process.converge-container', $code, [...$details, 'output' => ['exit_code' => 1, 'stdout' => 'secret-value', 'stderr' => 'failed with secret-value']]);

    expect(fn () => $this->manager->converge($process))->toThrow(function (ProcessOperationException $exception) use ($details, $expectedCode, $expectedMessage, $name): void {
        expect($exception->errorCode)->toBe($expectedCode)
            ->and($exception->step)->toBe($details['step'])
            ->and($exception->getMessage())->toBe(str_replace('{name}', $name, $expectedMessage))
            ->and($exception->result?->stdout)->toBe('[REDACTED]')
            ->and($exception->result?->stderr)->toBe('failed with [REDACTED]');
    });
})->with([
    'replacement start' => ['process.start_failed', ['step' => 'start', 'restored' => true], 'process.start_failed', 'Process [redis] failed to start; the previous container was restored.'],
    'desired start' => ['process.start_failed', ['step' => 'start', 'restored' => false], 'process.start_failed', 'Process [redis] runtime step [start] failed.'],
    'activation' => ['process.activation_failed', ['step' => 'activate-container'], 'process.docker_activation_failed', 'Docker process [redis] activation failed; the previous container was restored.'],
    'ambiguous rollback' => ['process.recovery_required', ['step' => 'inspect-rollback-container', 'reason' => 'ambiguous_rollback'], 'process.docker_recovery_required', 'Docker process [redis] has ambiguous rollback state.'],
    'ambiguous candidate' => ['process.recovery_required', ['step' => 'inspect-rollback-container', 'reason' => 'ambiguous_candidate'], 'process.docker_recovery_required', 'Docker process [redis] has ambiguous candidate and rollback state.'],
    'no canonical' => ['process.recovery_required', ['step' => 'inspect-container', 'reason' => 'no_canonical'], 'process.docker_recovery_required', 'Docker process [redis] has no canonical runtime after recovery.'],
    'restore' => ['process.recovery_required', ['step' => 'restore-container', 'reason' => 'step_failed'], 'process.docker_recovery_required', 'Process [redis] runtime step [restore-container] failed.'],
    'candidate' => ['process.candidate_invalid', ['step' => 'verify-candidate-container', 'artifact' => 'orbit-process-1-redis-candidate'], 'process.docker_candidate_invalid', 'Docker candidate [orbit-process-1-redis-candidate] does not match the desired process specification.'],
    'collision' => ['process.name_collision', ['step' => 'inspect-container', 'artifact' => '{name}'], 'process.runtime_name_collision', 'Docker container [{name}] is not owned by this process.'],
    'create' => ['process.converge_failed', ['step' => 'create-container'], 'process.docker_converge_failed', 'Process [redis] runtime step [create-container] failed.'],
]);

it('serializes runtime convergence for the same node and process', function (): void {
    $process = runtime_manager_docker_process($this->instance);
    $lock = Cache::lock("orbit:process-runtime:{$this->instance->node_id}:{$process->id}", 60);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => $this->manager->converge($process))->toThrow(function (ProcessOperationException $exception): void {
            expect($exception->errorCode)->toBe('process.runtime_lock_failed')
                ->and($exception->step)->toBe('lock-runtime');
        });
        expect($this->agents->calls)->toBeEmpty();
    } finally {
        $lock->release();
    }
});

it('serializes lifecycle mutations with runtime convergence', function (string $operation): void {
    $process = runtime_manager_docker_process($this->instance);
    $lock = Cache::lock("orbit:process-runtime:{$this->instance->node_id}:{$process->id}", 60);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => match ($operation) {
            'start' => $this->manager->start($process),
            'stop' => $this->manager->stop($process),
            'restart' => $this->manager->restart($process),
            'remove' => $this->manager->remove($process),
        })->toThrow(function (ProcessOperationException $exception): void {
            expect($exception->errorCode)->toBe('process.runtime_lock_failed')
                ->and($exception->step)->toBe('lock-runtime');
        });
        expect($this->agents->calls)->toBeEmpty();
    } finally {
        $lock->release();
    }
})->with(['start', 'stop', 'restart', 'remove']);

it('shares the process runtime owner with role-dependent cleanup', function (): void {
    $process = runtime_manager_docker_process($this->instance);
    $lock = Cache::lock("orbit:process-runtime:{$this->instance->node_id}:{$process->id}", 60);
    expect($lock->get())->toBeTrue();

    try {
        $cleaner = new NativeNodeRoleDependentCleaner(processes: $this->manager);

        expect(fn () => $cleaner->clean(new NodeRoleDependencySet(
            instanceIds: [],
            workspaceIds: [],
            processIds: [$process->id],
            summaries: [],
        )))->toThrow(function (NodeRoleOperationException $exception): void {
            expect($exception->underlyingErrorCode)->toBe('process.runtime_lock_failed')
                ->and($exception->step)->toBe('process-runtime');
        });

        expect($this->agents->calls)->toBeEmpty()
            ->and($process->refresh()->status)->toBe(LifecycleStatus::Active)
            ->and($process->refresh()->error_code)->toBeNull();
    } finally {
        $lock->release();
    }
});

it('maps lifecycle refusals to the operation code', function (string $operation, string $code, string $expectedCode, string $step): void {
    $process = runtime_manager_systemd_process($this->instance);
    $this->agents->refuse('process.control', $code, ['step' => $step, 'restored' => false, 'artifact' => 'unit', 'output' => ['exit_code' => 1, 'stdout' => '', 'stderr' => '']]);

    expect(fn () => match ($operation) {
        'start' => $this->manager->start($process),
        'stop' => $this->manager->stop($process),
        'restart' => $this->manager->restart($process),
    })->toThrow(function (ProcessOperationException $exception) use ($expectedCode, $step): void {
        expect($exception->errorCode)->toBe($expectedCode)->and($exception->step)->toBe($step);
    });
    expect($this->agents->calls[0]['input']['operation'])->toBe($operation);
})->with([
    ['start', 'process.start_failed', 'process.start_failed', 'start'],
    ['stop', 'process.not_found', 'process.runtime_not_found', 'stop'],
    ['restart', 'process.restart_failed', 'process.restart_failed', 'restart-enable'],
    ['restart', 'process.name_collision', 'process.runtime_name_collision', 'restart'],
]);

it('fails the step with its own code when no agent result arrives', function (): void {
    $process = runtime_manager_systemd_process($this->instance);
    $this->agents->answer('process.control', static fn (): array => throw new AgentRunnerFailure('process.control', AgentRunnerFailure::Transport, 'Lost.', result: new CommandResult(255, '', 'Connection reset', 0, false)));

    expect(fn () => $this->manager->stop($process))->toThrow(function (ProcessOperationException $exception): void {
        expect($exception->errorCode)->toBe('process.stop_failed')
            ->and($exception->step)->toBe('stop')
            ->and($exception->result?->stderr)->toBe('Connection reset');
    });
});

it('maps an undeclared Process refusal to the step code', function (): void {
    $process = runtime_manager_systemd_process($this->instance);
    $this->agents->refuse('process.status', 'agent.target_busy', ['lock' => '/run/lock']);

    expect(fn () => $this->manager->status($process))->toThrow(function (ProcessOperationException $exception): void {
        expect($exception->errorCode)->toBe('process.status_failed')->and($exception->step)->toBe('status');
    });
});

it('starts an on-demand host Process without enabling it', function (): void {
    $process = runtime_manager_systemd_process($this->instance);
    $this->agents->answer('process.control', []);
    $target = new ProcessTargetResolver()->forStart($process);

    $this->manager->start($process);

    expect($this->agents->calls[0]['input']['on_demand'])->toBe($target->onDemandHostStart);
});

it('reads Docker logs as whole lines', function (): void {
    $process = runtime_manager_docker_process($this->instance);
    $this->agents->answer('process.logs', ['output' => "out one\nerr one\n"]);

    expect($this->manager->logs($process, 20))->toBe("out one\nerr one\n")
        ->and($this->agents->calls[0]['input'])->toBe([
            'process_id' => $process->id,
            'runtime' => 'docker',
            'name' => "orbit-process-{$process->id}-redis",
            'lines' => 20,
            'max_bytes' => LogReadLimit::Bytes,
        ]);
});

it('reports the agent status of a Process', function (string $state): void {
    $process = runtime_manager_docker_process($this->instance);
    $this->agents->answer('process.status', ['state' => $state]);

    expect($this->manager->status($process))->toBe($state);
})->with(['absent', 'running', 'exited']);

it('removes a Vite+ dev Process with its Instance environment file', function (): void {
    $process = runtime_manager_systemd_process($this->instance);
    $process->update(['runtime_config' => [...$process->runtime_config, 'preset' => VpDevPreset::NAME]]);
    $this->agents->answer('process.remove', []);

    $this->manager->remove($process->refresh());

    expect($this->agents->calls[0]['input']['vite'])->toBe([
        'path' => "/etc/orbit/vite/app-instance-{$this->instance->id}.env",
        'instance_id' => $this->instance->id,
    ]);
});

it('maps a refused Vite environment cleanup', function (): void {
    $process = runtime_manager_systemd_process($this->instance);
    $this->agents->refuse('process.remove', 'process.vite_cleanup_failed', ['step' => 'remove-vite-environment', 'output' => ['exit_code' => 1, 'stdout' => '', 'stderr' => '']]);

    expect(fn () => $this->manager->remove($process))->toThrow(function (ProcessOperationException $exception): void {
        expect($exception->errorCode)->toBe('vite.environment_cleanup_failed')
            ->and($exception->step)->toBe('remove-vite-environment');
    });
});

it('removes an instance process after its role and resources enter removing state', function (): void {
    $process = runtime_manager_systemd_process($this->instance);
    $process->update(['status' => LifecycleStatus::Removing]);
    $this->instance->update(['status' => InstanceState::SourceResolved]);
    $this->instance->node->roles()->where('role', 'app-dev')->update(['status' => LifecycleStatus::Removing]);
    $this->agents->answer('process.remove', []);

    $this->manager->remove($process);

    expect($this->agents->commands())->toBe(['process.remove'])
        ->and($this->agents->calls[0]['node'])->toBe('app-dev');
});

it('removes the retired runtime footprint without losing its override when the lease refreshes the Process', function (): void {
    $process = runtime_manager_systemd_process($this->instance);
    $process->update(['runtime' => ProcessRuntime::Docker, 'runtime_config' => ['image' => 'redis:8-alpine', 'command' => ['redis-server']]]);
    $retired = clone $process;
    $retired->runtime = ProcessRuntime::Systemd;
    $this->agents->answer('process.remove', []);

    $this->manager->remove($retired);

    expect($this->agents->calls[0]['input'])->toMatchArray(['runtime' => 'systemd', 'name' => "orbit-process-{$process->id}-queue.service"])
        ->and($process->refresh()->runtime)->toBe(ProcessRuntime::Docker);
});

it('derives the production removal target from persisted Instance identity', function (): void {
    $process = runtime_manager_systemd_process($this->instance);
    $process->update(['status' => LifecycleStatus::Removing]);
    $this->instance->update([
        'status' => InstanceState::SourceResolved,
        'environment' => 'production',
        'checkout_path' => '/home/orbit-docs/releases/20260910',
        'production_user' => 'orbit-docs',
        'production_home' => '/home/orbit-docs',
    ]);
    runtime_manager_place_on_app_prod($this->instance);

    $target = new ProcessTargetResolver()->forRemoval($process);

    expect($target->user)->toBe('orbit-docs')
        ->and($target->checkoutPath)->toBe('/home/orbit-docs/current')
        ->and($target->node->is($this->instance->node))->toBeTrue();
});

it('checks the selected production release before start', function (): void {
    $process = runtime_manager_systemd_process($this->instance);
    $process->update(['working_directory' => '/home/orbit-docs/current']);
    runtime_manager_make_production($this->instance);
    $this->agents->answer('process.check-release', ['available' => true])->answer('process.control', []);

    $this->manager->start($process);

    expect($this->agents->commands())->toBe(['process.check-release', 'process.control'])
        ->and($this->agents->calls[0]['input'])->toBe(['directory' => '/home/orbit-docs/current']);
});

it('refuses production start and convergence before runtime mutation when no release is selected', function (string $operation): void {
    $process = runtime_manager_systemd_process($this->instance);
    if ($operation === 'converge running') {
        $process->update(['desired_state' => 'running']);
    }
    runtime_manager_make_production($this->instance);
    $this->agents->answer('process.check-release', ['available' => false]);

    expect(fn () => $operation === 'start' ? $this->manager->start($process) : $this->manager->converge($process))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('process.release_unavailable');
        });

    expect($this->agents->commands())->toBe(['process.check-release']);
})->with(['start', 'converge stopped', 'converge running']);

it('keeps the active-node prerequisite on the removal-only target path', function (): void {
    $process = runtime_manager_systemd_process($this->instance);
    $this->instance->update(['status' => InstanceState::SourceResolved]);
    $this->instance->node->update(['status' => LifecycleStatus::Failed]);

    expect(fn () => new ProcessTargetResolver()->forRemoval($process))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('process.target_inactive');
        });
});

it('rejects leftover Workspace ownership before runtime cleanup', function (): void {
    $process = runtime_manager_leftover_workspace_process();

    expect(fn () => $this->manager->remove($process))
        ->toThrow(ResourceOperationException::class, 'not a supported Instance or Node');

    expect($this->agents->calls)->toBeEmpty();
});

it('refuses a Node without a WireGuard address before any agent call', function (): void {
    $process = runtime_manager_systemd_process($this->instance);
    $this->instance->node->update(['wireguard_ip' => null]);

    expect(fn () => $this->manager->status($process))->toThrow(function (ProcessOperationException $exception): void {
        expect($exception->errorCode)->toBe('process.wireguard_ip_missing');
    });
    expect($this->agents->calls)->toBeEmpty();
});

function runtime_manager_rendered_unit(Process $process): string
{
    $target = new ProcessTargetResolver()->forInstallation($process);

    return new SystemdProcessRenderer()->render($process, $target, new ProcessRuntimeFakeManagedUserAccountResolver()->resolve($target->node));
}

function runtime_manager_make_production(Instance $instance): void
{
    $instance->update([
        'environment' => 'production',
        'checkout_path' => '/home/orbit-docs/releases/20260910',
        'production_user' => 'orbit-docs',
        'production_home' => '/home/orbit-docs',
    ]);
    runtime_manager_place_on_app_prod($instance);
}

function runtime_manager_place_on_app_prod(Instance $instance): void
{
    $instance->loadMissing('node.roles');
    $instance->node->roles()->where('role', 'app-dev')->delete();

    if (! $instance->node->roles()->where('role', 'app-prod')->exists()) {
        $instance->node->roles()->create(['role' => 'app-prod', 'status' => LifecycleStatus::Active]);
    }

    $instance->unsetRelation('node');
    $instance->load('node.roles');
}

function runtime_manager_systemd_process(Instance $instance): Process
{
    return Process::query()->create([
        'owner_type' => Instance::MorphAlias,
        'owner_id' => $instance->id,
        'name' => 'queue',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => $instance->checkout_path,
        'runtime_config' => [
            'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
            'environment_file' => $instance->checkout_path.'/.env',
        ],
        'restart_policy' => 'always',
        'desired_state' => 'stopped',
        'status' => LifecycleStatus::Active,
    ]);
}

function runtime_manager_leftover_workspace_process(): Process
{
    return Process::query()->create([
        'owner_type' => 'App\\Models\\Workspace',
        'owner_id' => 999_999,
        'name' => 'queue',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => '/srv/legacy',
        'runtime_config' => [
            'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
            'environment_file' => '/srv/legacy/.env',
        ],
        'restart_policy' => 'always',
        'desired_state' => 'stopped',
        'status' => LifecycleStatus::Active,
    ]);
}

/** @param array<string, string> $environment */
function runtime_manager_docker_process(Instance $instance, array $environment = []): Process
{
    return Process::query()->create([
        'owner_type' => Instance::MorphAlias,
        'owner_id' => $instance->id,
        'name' => 'redis',
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/data',
        'runtime_config' => [
            'image' => 'redis:8-alpine',
            'command' => ['redis-server'],
            'environment' => $environment,
            'ports' => ['127.0.0.1:6380:6379/tcp'],
            'volumes' => [['source' => 'redis-data', 'target' => '/data', 'read_only' => false]],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => 'stopped',
        'status' => LifecycleStatus::Active,
    ]);
}

final readonly class ProcessRuntimeFakeManagedUserAccountResolver implements ManagedUserAccountResolver
{
    public function resolve(Node $node): ManagedUserAccount
    {
        return new ManagedUserAccount($node->user, 'orbit', '/home/orbit');
    }
}
