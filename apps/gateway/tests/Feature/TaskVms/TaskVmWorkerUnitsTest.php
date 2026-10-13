<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\TaskVms\TaskVmQueue;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\TaskVms\TaskVmWorkerUnits;
use Illuminate\Support\Facades\File;
use Tests\Support\RecordingProcessRunner;

/** @return array{TaskVmWorkerUnits, RecordingProcessRunner, object{restarts: int}} */
function tvm_worker_units(?ProcessRunner $processes = null): array
{
    $processes ??= new RecordingProcessRunner;
    $probe = new class
    {
        public int $restarts = 0;
    };

    return [
        new TaskVmWorkerUnits(
            $processes,
            phpBinary: '/usr/bin/php8.5',
            applicationPath: '/home/orbit/orbit/apps/gateway',
            orbitHome: '/home/orbit/.orbit',
            unitDirectory: test()->unitDirectory,
            restartWorkers: static function () use ($probe): void {
                $probe->restarts++;
            },
        ),
        $processes,
        $probe,
    ];
}

/** @param list<list<string>> $ran */
function tvm_worker_commands(array $ran): array
{
    return array_map(static fn (array $arguments): string => implode(' ', $arguments), array_values(array_filter($ran, static fn (array $arguments): bool => $arguments[1] !== 'install')));
}

beforeEach(function (): void {
    $this->unitDirectory = sys_get_temp_dir().'/tvm-worker-units-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->unitDirectory);
    config(['task_vms.enabled' => false, 'task_vms.upcloud.token_file' => null]);
});

afterEach(function (): void {
    File::deleteDirectory($this->unitDirectory);
});

describe(TaskVmWorkerUnits::class, function (): void {
    it('renders one long-running worker per queue that finishes its job when systemd stops it', function (): void {
        [$units] = tvm_worker_units();
        $git = $units->render(TaskVmQueue::Git, '/home/orbit/orbit/apps/gateway', '/home/orbit/.orbit');

        expect($units->unitName(TaskVmQueue::Git))->toBe('orbit-queue-task-vm-git.service')
            ->and($git)->toContain("User=orbit\n", "WorkingDirectory=/home/orbit/orbit/apps/gateway\n", "Environment=ORBIT_HOME=/home/orbit/.orbit\n")
            ->and($git)->toContain('ExecStart="/usr/bin/php8.5" "/home/orbit/orbit/apps/gateway/artisan" "queue:work" "task-vms" "--queue=task-vm-git" "--sleep=1" "--timeout=1700"'."\n")
            ->and($git)->toContain("Restart=always\n", "RestartSec=5\n", "KillMode=mixed\n", "TimeoutStopSec=1800\n", "WantedBy=multi-user.target\n")
            ->and($git)->not->toContain('--stop-when-empty', '--max-time')
            ->and($units->render(TaskVmQueue::Vms, '/a', '/h'))->toContain('"--queue=task-vms" "--sleep=1" "--timeout=1500"')
            // A stop outlasts every job.
            ->and(collect(TaskVmQueue::cases())->every(static fn (TaskVmQueue $queue): bool => $queue->workerTimeout() < TaskVmWorkerUnits::StopSeconds))->toBeTrue();
    });

    it('installs and starts the workers while task VMs are enabled, after asking the running ones to restart', function (): void {
        config(['task_vms.enabled' => true]);
        [$units, $processes, $probe] = tvm_worker_units();

        expect($units->converge())->toBe('started')
            ->and(array_map(static fn (array $arguments): string => implode(' ', [...array_slice($arguments, 0, 4), end($arguments)]), array_slice($processes->ran, 0, 4)))->toBe(array_map(
                fn (TaskVmQueue $queue): string => 'sudo install -m 0644 '.$this->unitDirectory.'/orbit-queue-'.$queue->value.'.service',
                TaskVmQueue::cases(),
            ))
            ->and(tvm_worker_commands($processes->ran))->toBe([
                'sudo systemctl daemon-reload',
                'sudo systemctl enable --now orbit-queue-task-vms.service orbit-queue-task-vm-templates.service orbit-queue-task-vm-images.service orbit-queue-task-vm-git.service',
            ])
            ->and($probe->restarts)->toBe(1);
    });

    it('starts the workers while only the UpCloud token file is set', function (): void {
        config(['task_vms.upcloud.token_file' => '/home/orbit/.orbit/upcloud-token']);

        expect(tvm_worker_units()[0]->converge())->toBe('started');
    });

    it('changes nothing on a Gateway without task VMs that never had the workers', function (): void {
        [$units, $processes, $probe] = tvm_worker_units();

        expect($units->converge())->toBe('none')
            ->and($processes->ran)->toBe([])
            ->and($probe->restarts)->toBe(0);
    });

    it('stops and removes the workers it finds once task VMs are off, without waiting for a running job', function (): void {
        File::put($this->unitDirectory.'/orbit-queue-task-vms.service', '');
        File::put($this->unitDirectory.'/orbit-queue-task-vm-git.service', '');
        [$units, $processes] = tvm_worker_units();

        expect($units->converge())->toBe('stopped')
            ->and(tvm_worker_commands($processes->ran))->toBe([
                'sudo systemctl disable --now --no-block orbit-queue-task-vms.service orbit-queue-task-vm-git.service',
                "sudo rm -f {$this->unitDirectory}/orbit-queue-task-vms.service {$this->unitDirectory}/orbit-queue-task-vm-git.service",
                'sudo systemctl daemon-reload',
            ]);
    });

    it('fails when systemd refuses the workers', function (): void {
        config(['task_vms.enabled' => true]);
        $processes = new class implements ProcessRunner
        {
            public function run(ProcessInvocation $invocation): CommandResult
            {
                return $invocation->arguments[2] === 'enable' ? new CommandResult(1, '', 'denied', 1, false) : new CommandResult(0, '', '', 1, false);
            }
        };

        expect(fn () => tvm_worker_units($processes)[0]->converge())
            ->toThrow(fn (NodeProvisioningException $exception) => expect($exception->errorCode)->toBe('task_vm.workers_failed'));
    });
});
