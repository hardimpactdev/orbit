<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs a copy of `base-image-clean.sh` as the image build sends it, on stdin to `bash -s`, against a temporary root:
 * every absolute path of the script moves below it, and `systemctl`, `apt-get`, `journalctl`, and `cloud-init` are
 * stubs that log their arguments. `cloud-init clean` resets the machine ID as the real one does. `find` is the real
 * one, except that with `KEEP_INCUS` set it deletes nothing in the Incus directory.
 *
 * @param  Closure(string): void  $prepare  fills the temporary root before the run
 * @param  array<string, string>  $environment
 * @return array{Process, string} the finished process and the temporary root
 */
function base_image_clean(Closure $prepare, array $environment = []): array
{
    $root = sys_get_temp_dir().'/orbit-base-image-clean-'.bin2hex(random_bytes(6));
    foreach (['bin', 'etc/ssh', 'var/lib/incus/database', 'var/lib/cloud', 'var/log', 'var/tmp', 'tmp', 'root', 'home/orbit/.ssh'] as $directory) {
        mkdir("{$root}/{$directory}", 0700, true);
    }
    file_put_contents("{$root}/etc/machine-id", "0123456789abcdef0123456789abcdef\n");
    $prepare($root);
    $stubs = [
        // Every unit of the image is loaded; the log keeps what the script stopped.
        'systemctl' => '[ "$1" = show ] && { echo loaded; exit 0; }; echo "systemctl $*" >> "$ROOT/calls"',
        'apt-get' => 'echo "apt-get $*" >> "$ROOT/calls"',
        'journalctl' => 'exit 0',
        'cloud-init' => 'echo uninitialized > "$ROOT/etc/machine-id"; rm -rf "$ROOT/var/lib/cloud/instance"',
        'find' => '[ -n "${KEEP_INCUS:-}" ] && [ "$1" = "$ROOT/var/lib/incus" ] && [ "$*" = "$1 -mindepth 1 -delete" ] && exit 0; exec /usr/bin/find "$@"',
    ];
    foreach ($stubs as $name => $body) {
        file_put_contents("{$root}/bin/{$name}", "#!/bin/sh\n{$body}\n");
        chmod("{$root}/bin/{$name}", 0755);
    }
    $script = (string) preg_replace('#(?<=[\s(=>\'"])/(var|etc|root|home|tmp)\b#', '$ROOT/$1', (string) file_get_contents(resource_path('task-vms/base-image-clean.sh')));
    // The run must never reach a real system path.
    expect(preg_match('#(?<![\w$])/(var|etc|root|home|tmp)\b#', $script))->toBe(0);
    $bash = new ExecutableFinder()->find('bash') ?? '/bin/bash';
    $process = new Process([$bash, '-s']);
    $process->setInput($script);
    $process->setEnv(['PATH' => "{$root}/bin:".getenv('PATH'), 'ROOT' => $root, ...$environment]);
    $process->run();

    return [$process, $root];
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/orbit-base-image-clean-*') ?: [] as $root) {
        new Process(['rm', '-rf', '--', $root])->run();
    }
});

it('stops Incus and empties its state, so every VM from the image creates its own server', function (): void {
    [$process, $root] = base_image_clean(function (string $root): void {
        file_put_contents("{$root}/var/lib/incus/server.crt", 'certificate');
        file_put_contents("{$root}/var/lib/incus/database/global.db", 'database');
    });

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe("{\"ok\":true}\n")
        ->and(is_dir("{$root}/var/lib/incus"))->toBeTrue()
        ->and(scandir("{$root}/var/lib/incus"))->toBe(['.', '..'])
        ->and(file_get_contents("{$root}/calls"))->toContain('systemctl stop caddy.service docker.service docker.socket containerd.service incus.socket incus-user.socket incus.service incus-user.service');
});

it('fails without a confirmation when Incus state remains', function (): void {
    [$process] = base_image_clean(function (string $root): void {
        file_put_contents("{$root}/var/lib/incus/database/global.db", 'database');
    }, ['KEEP_INCUS' => '1']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('')
        // The sandbox moved the path in the message too.
        ->and($process->getErrorOutput())->toContain('base-image-clean: the Incus state in $ROOT/var/lib/incus remains');
});
