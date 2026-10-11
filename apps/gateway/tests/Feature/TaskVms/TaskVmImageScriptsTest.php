<?php

declare(strict_types=1);

use App\Infrastructure\TaskVms\IncusTaskVmImageSync;
use App\Infrastructure\TaskVms\TaskVmImagePeers;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs the image transfer scripts with bash, with stubs for the commands that need root, Incus, or a peer.
 *
 * @param  array<string, string>  $stubs  command => shell body
 * @param  list<string>  $arguments
 * @return array{Process, string} the finished process and its work directory
 */
function image_script(string $script, array $arguments, array $stubs, string $input = ''): array
{
    $work = sys_get_temp_dir().'/orbit-image-script-'.bin2hex(random_bytes(6));
    mkdir($work.'/bin', 0700, true);
    foreach ($stubs as $name => $body) {
        file_put_contents("{$work}/bin/{$name}", "#!/bin/sh\n{$body}\n");
        chmod("{$work}/bin/{$name}", 0755);
    }
    $bash = new ExecutableFinder()->find('bash') ?? '/bin/bash';
    $process = new Process([$bash, '-ceu', $script, '--', ...$arguments]);
    $process->setInput($input);
    $process->setEnv(['PATH' => $work.'/bin:'.getenv('PATH'), 'WORK' => $work]);
    $process->run();

    return [$process, $work];
}

function image_script_constant(string $class, string $name): string
{
    return (string) new ReflectionClassConstant($class, $name)->getValue();
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/orbit-image-script-*') ?: [] as $work) {
        new Process(['rm', '-rf', '--', $work])->run();
    }
});

describe('the receiver', function (): void {
    beforeEach(function (): void {
        $this->receiver = (string) file_get_contents(resource_path('task-vms/image-receive.sh'));
        $this->stubs = [
            'install' => ':',
            'find' => ':',
            'mktemp' => 'touch "$WORK/image.received" && echo "$WORK/image.received"',
            'incus' => 'cp "$6" "$WORK/imported" && echo "incus $*" >> "$WORK/incus.log" && exit "${INCUS_EXIT:-0}"',
        ];
    });

    it('imports the image into the project from its argument and prints its fingerprint', function (): void {
        [$process, $work] = image_script($this->receiver, ['orbit-tasks'], $this->stubs, 'the image bytes');

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toBe(hash('sha256', 'the image bytes')."\n")
            ->and(file_get_contents($work.'/incus.log'))->toBe("incus image import --quiet --project orbit-tasks {$work}/image.received\n")
            ->and(file_get_contents($work.'/imported'))->toBe('the image bytes')
            // The staging file is gone once the import is done.
            ->and(file_exists($work.'/image.received'))->toBeFalse();
    });

    it('answers a probe with ready and imports nothing', function (): void {
        [$process, $work] = image_script($this->receiver, ['orbit-tasks'], $this->stubs);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toBe("ready\n")
            ->and(file_exists($work.'/incus.log'))->toBeFalse();
    });

    it('fails loudly and removes the staging file when the import fails', function (): void {
        $this->stubs['incus'] = 'echo "Error: Image with same fingerprint already exists" >&2; exit 1';
        [$process, $work] = image_script($this->receiver, ['orbit-tasks'], $this->stubs, 'the image bytes');

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput())->toBe('')
            ->and($process->getErrorOutput())->toContain('Image with same fingerprint already exists')
            ->and(file_exists($work.'/image.received'))->toBeFalse();
    });
});

describe('the source scripts', function (): void {
    it('exports one image file into the empty private directory', function (): void {
        $work = sys_get_temp_dir().'/orbit-image-script-'.bin2hex(random_bytes(6));
        $directory = "{$work}/export/node-13";
        mkdir($directory.'/stale', 0700, true);
        [$process] = image_script(image_script_constant(IncusTaskVmImageSync::class, 'Export'), [$directory, str_repeat('a', 64), 'orbit-tasks'], [
            'install' => 'for path; do case $path in /*) mkdir -p -- "$path" ;; esac; done',
            'incus' => 'echo "incus $*" >> "$WORK/incus.log"; printf image > "$7.tar"',
        ]);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toBe("{$directory}/image.tar\n")
            ->and(scandir($directory))->toBe(['.', '..', 'image.tar']);
        new Process(['rm', '-rf', '--', $work])->run();
    });

    it('sends with the transfer key from the source address and pins the target host key', function (): void {
        [$process, $work] = image_script(image_script_constant(TaskVmImagePeers::class, 'Send'), [
            '/dev/null', '/etc/orbit/task-vms/image-transfer/id_ed25519', '192.168.6.20', 'ssh-ed25519 AAAAhost', 'nckrtl', '192.168.6.40',
        ], [
            'ssh' => 'printf "%s\n" "$@" > "$WORK/ssh.args"; for arg; do case $arg in UserKnownHostsFile=*) cp "${arg#UserKnownHostsFile=}" "$WORK/known_hosts" ;; esac; done; echo ready',
        ]);

        $arguments = explode("\n", trim((string) file_get_contents($work.'/ssh.args')));
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toBe("ready\n")
            ->and(file_get_contents($work.'/known_hosts'))->toBe("192.168.6.40 ssh-ed25519 AAAAhost\n")
            ->and(array_slice($arguments, 0, 11))->toBe(['-F', '/dev/null', '-T', '-i', '/etc/orbit/task-vms/image-transfer/id_ed25519', '-b', '192.168.6.20', '-l', 'nckrtl', '-p', '22'])
            ->and($arguments)->toContain('BatchMode=yes', 'IdentitiesOnly=yes', 'StrictHostKeyChecking=yes', 'GlobalKnownHostsFile=/dev/null', 'HostKeyAlgorithms=ssh-ed25519')
            ->and(array_slice($arguments, -2))->toBe(['--', '192.168.6.40']);
    });
});

describe('the peer key writer', function (): void {
    it('replaces the peer key lines and keeps every other line', function (): void {
        $work = sys_get_temp_dir().'/orbit-image-script-'.bin2hex(random_bytes(6));
        mkdir($work.'/home/.ssh', 0700, true);
        file_put_contents($work.'/home/.ssh/authorized_keys', "ssh-ed25519 AAAAnick nick@mac\nfrom=\"192.168.6.99\",restrict,command=\"x\" ssh-ed25519 AAAAold orbit-task-image-99\n\nssh-ed25519 AAAAorbit orbit@gateway\n");
        $script = image_script_constant(TaskVmImagePeers::class, 'AuthorizedKeys');
        $stubs = [
            'getent' => 'echo "nckrtl:x:1000:1000::$WORK/home:/bin/bash"',
            'id' => 'echo nckrtl',
            'chown' => ':',
        ];
        // The stubs and the home are in the same work directory, so the helper must see it.
        $run = static function (string $drop, string $input) use ($script, $stubs, $work): Process {
            mkdir($work.'/bin', 0700, true);
            foreach ($stubs as $name => $body) {
                file_put_contents("{$work}/bin/{$name}", "#!/bin/sh\n{$body}\n");
                chmod("{$work}/bin/{$name}", 0755);
            }
            $process = new Process([new ExecutableFinder()->find('bash') ?? '/bin/bash', '-ceu', $script, '--', 'nckrtl', $drop]);
            $process->setInput($input);
            $process->setEnv(['PATH' => $work.'/bin:'.getenv('PATH'), 'WORK' => $work]);
            $process->run();
            new Process(['rm', '-rf', '--', $work.'/bin'])->run();

            return $process;
        };

        $process = $run('^orbit-task-image-[0-9]+$', "from=\"192.168.6.40\",restrict,command=\"y\" ssh-ed25519 AAAAshark orbit-task-image-13\n");

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(file_get_contents($work.'/home/.ssh/authorized_keys'))->toBe("ssh-ed25519 AAAAnick nick@mac\n\nssh-ed25519 AAAAorbit orbit@gateway\nfrom=\"192.168.6.40\",restrict,command=\"y\" ssh-ed25519 AAAAshark orbit-task-image-13\n")
            ->and(decoct(fileperms($work.'/home/.ssh/authorized_keys') & 0777))->toBe('600');

        $process = $run('^orbit-task-image-13$', '');

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(file_get_contents($work.'/home/.ssh/authorized_keys'))->toBe("ssh-ed25519 AAAAnick nick@mac\n\nssh-ed25519 AAAAorbit orbit@gateway\n")
            ->and(glob($work.'/home/.ssh/.authorized_keys.*'))->toBe([]);
        new Process(['rm', '-rf', '--', $work])->run();
    });
});
