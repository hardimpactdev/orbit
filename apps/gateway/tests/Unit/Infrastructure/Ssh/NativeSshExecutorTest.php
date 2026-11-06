<?php

declare(strict_types=1);

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\NativeSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshControlGroup;

/** The ssh arguments of one command that runs in the control group the file names. */
function sshArgumentsIn(string $group, bool $share = true): array
{
    // A short path: the socket directory must leave room for a Unix socket name.
    $home = '/tmp/ssh'.bin2hex(random_bytes(4));
    mkdir($home, 0700, true);
    file_put_contents($home.'/cgroup', $group);
    $runner = new class implements ProcessRunner
    {
        /** @var list<string> */
        public array $arguments = [];

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->arguments = $invocation->arguments;

            return new CommandResult(0, '', '', 1, false);
        }
    };
    $connection = new SshConnection('10.44.0.9', 'orbit', 22, $home.'/key', $home.'/known_hosts', shareConnection: $share);
    (new NativeSshExecutor($runner, new SshControlGroup($home.'/cgroup')))->execute($connection, new RemoteCommand(['true']));

    return $runner->arguments;
}

it('runs a command on the shared connection of the Node outside Horizon', function (string $group): void {
    $arguments = sshArgumentsIn($group);

    expect($arguments)->toContain('ControlMaster=auto')->and($arguments)->toContain('ControlPersist=60s');
})->with([
    'a PHP-FPM pool' => ["0::/system.slice/php8.5-fpm.service\n"],
    'the scheduler' => ["0::/system.slice/orbit-process-108-schedule-work.service\n"],
    'a terminal session' => ["0::/user.slice/user-1000.slice/session-1.scope\n"],
]);

it('gives a command in the Horizon unit its own connection, so no master stays in the group that a release drains', function (): void {
    $arguments = sshArgumentsIn("0::/system.slice/orbit-process-154-horizon.service\n");

    expect($arguments)->not->toContain('ControlMaster=auto')->and($arguments)->not->toContain('ControlPersist=60s');
});

it('keeps a connection that asked for no sharing unshared', function (): void {
    expect(sshArgumentsIn("0::/system.slice/php8.5-fpm.service\n", share: false))->not->toContain('ControlMaster=auto');
});
