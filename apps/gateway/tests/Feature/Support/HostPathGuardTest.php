<?php

declare(strict_types=1);

use App\Infrastructure\Processes\LockRenewingProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use Tests\Support\HostPathGuardedProcessRunner;
use Tests\Support\LocalShellSshExecutor;

it('refuses a privileged command that names a managed host path before it runs', function (string $input): void {
    $marker = sys_get_temp_dir().'/host-path-guard-'.bin2hex(random_bytes(4));

    expect(fn () => app(ProcessRunner::class)->run(new ProcessInvocation(
        ['sudo', '-n', 'bash', '-seu'],
        input: "touch '{$marker}'\n{$input}\n",
    )))->toThrow(LogicException::class, 'privileged command against the host path');

    expect(file_exists($marker))->toBeFalse()
        ->and(app(ProcessRunner::class))->toBeInstanceOf(LockRenewingProcessRunner::class);
})->with([
    'systemd unit' => [': unit_directory=/etc/systemd/system'],
    'Orbit state' => [': "/var/lib/orbit/private-dns/catalog.json"'],
    'dnsmasq fragment' => [': /etc/dnsmasq.d/orbit-records.conf'],
    'dnsmasq configuration' => [': /etc/dnsmasq.conf'],
]);

it('allows the same paths under a temporary root and unprivileged reads', function (): void {
    HostPathGuardedProcessRunner::assertSafe(['sudo', 'bash', '-seu'], 'unit_directory=/tmp/orbit-x/etc/systemd/system');
    HostPathGuardedProcessRunner::assertSafe(['bash', '-seu'], posix_geteuid() === 0 ? 'true' : 'cat /etc/systemd/system/x');

    expect(true)->toBeTrue();
});

it('refuses sudo inside a script on stdin and in the local SSH executor', function (): void {
    expect(fn () => HostPathGuardedProcessRunner::assertSafe(['bash', '-seu'], "set -e\nsudo tee /etc/systemd/system/x.service\n"))
        ->toThrow(LogicException::class, 'host path /etc/systemd')
        ->and(fn () => new LocalShellSshExecutor()->execute(
            new SshConnection('127.0.0.1', 'orbit', 22, '/keys/id', '/keys/known_hosts'),
            new RemoteCommand(['bash', '-c', 'sudo -n install -d /var/lib/orbit/private-dns']),
        ))->toThrow(LogicException::class, 'host path /var/lib/orbit');
    if (posix_geteuid() !== 0) {
        HostPathGuardedProcessRunner::assertSafe(['bash', '-seu'], 'echo pseudo /etc/systemd');
    }
});
