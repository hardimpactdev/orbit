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
function sshArgumentsIn(string $group, bool $share = true, ?string $home = null, ?string $socketRoot = null): array
{
    // A short path: the socket directory must leave room for a Unix socket name.
    $home ??= '/tmp/ssh'.bin2hex(random_bytes(4));
    is_dir($home) || mkdir($home, 0700, true);
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
    new NativeSshExecutor($runner, new SshControlGroup($home.'/cgroup'), $socketRoot)->execute($connection, new RemoteCommand(['true']));

    return $runner->arguments;
}

it('starts the shared connection in a long-lived Gateway web service', function (string $service): void {
    $arguments = sshArgumentsIn("0::/system.slice/{$service}.service\n");

    expect($arguments)->toContain('ControlMaster=auto')->and($arguments)->toContain('ControlPersist=60s');
})->with(['php8.5-fpm', 'orbit-gateway-web']);

it('rides a running master or connects alone where the end of the control group kills a master started there', function (string $group): void {
    $arguments = sshArgumentsIn($group);
    $path = array_values(array_filter($arguments, static fn (string $argument): bool => str_starts_with($argument, 'ControlPath=')));

    expect($arguments)->toContain('ControlMaster=no')
        ->and($arguments)->not->toContain('ControlMaster=auto')
        ->and(implode(' ', $arguments))->not->toContain('ControlPersist')
        ->and($path)->toHaveCount(1)->and($path[0])->toEndWith('/mux/%C');
})->with([
    'the runtime hibernator, a oneshot' => ["0::/system.slice/orbit-runtime-hibernator.service\n"],
    'a transient unit of systemd-run' => ["0::/system.slice/run-u4711.service\n"],
    'the scheduler, which a release restarts' => ["0::/system.slice/orbit-process-108-schedule-work.service\n"],
    'artisan from an SSH shell' => ["0::/user.slice/user-1000.slice/session-1.scope\n"],
    'an unknown control group' => [''],
]);

it('rides the same socket that PHP-FPM starts', function (): void {
    $home = '/tmp/ssh'.bin2hex(random_bytes(4));
    $socket = static fn (array $arguments): array => array_values(array_filter($arguments,
        static fn (string $argument): bool => str_starts_with($argument, 'ControlPath=')));
    $master = $socket(sshArgumentsIn("0::/system.slice/php8.5-fpm.service\n", home: $home));

    expect($master)->toHaveCount(1)
        ->and($socket(sshArgumentsIn("0::/system.slice/orbit-runtime-hibernator.service\n", home: $home)))->toBe($master);
});

it('gives a command in the Horizon unit its own connection, so no master stays in the group that a release drains', function (): void {
    $arguments = sshArgumentsIn("0::/system.slice/orbit-process-154-horizon.service\n");

    expect(implode(' ', $arguments))->not->toContain('ControlMaster')->and($arguments)->not->toContain('ControlPersist=60s');
})->with(['php8.5-fpm', 'orbit-gateway-web']);

it('keeps a connection that asked for no sharing unshared', function (): void {
    expect(implode(' ', sshArgumentsIn("0::/system.slice/php8.5-fpm.service\n", share: false)))->not->toContain('ControlMaster');
});

it('keeps per-identity control sockets within the release container', function (): void {
    $root = '/tmp/mux'.bin2hex(random_bytes(4));
    $controlPath = static fn (array $arguments): string => array_find($arguments, static fn (string $arg): bool => str_starts_with($arg, 'ControlPath='));
    $first = $controlPath(sshArgumentsIn('0::/system.slice/orbit-gateway-web.service', socketRoot: $root));
    $other = $controlPath(sshArgumentsIn('0::/system.slice/orbit-gateway-web.service', socketRoot: $root));

    expect($first)->toStartWith('ControlPath='.$root.'/')->and($other)->not->toBe($first);
});
