<?php

declare(strict_types=1);

use App\Infrastructure\TaskVms\TaskVmScripts;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs a script of `resources/task-vms` with bash, with stubs for the commands that need root, Incus, or a peer.
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
    $process = new Process([$bash, TaskVmScripts::path($script), ...$arguments]);
    $process->setInput($input);
    $process->setEnv(['PATH' => $work.'/bin:'.getenv('PATH'), 'WORK' => $work]);
    $process->run();

    return [$process, $work];
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/orbit-image-script-*') ?: [] as $work) {
        new Process(['rm', '-rf', '--', $work])->run();
    }
});

describe('the image check', function (): void {
    beforeEach(function (): void {
        $this->work = sys_get_temp_dir().'/orbit-image-script-'.bin2hex(random_bytes(6));
        mkdir($this->work.'/home/.ssh', 0700, true);
        file_put_contents($this->work.'/home/.ssh/authorized_keys', "ssh-ed25519 AAAAgateway orbit@gateway\n");
        $this->stubs = [
            'id' => 'echo 1000',
            'getent' => 'echo "orbit:x:1000:1000::'.$this->work.'/home:/bin/bash"',
            'sudo' => 'echo "Status: active"',
        ];
    });

    it('passes for the pinned uid and gid and the Gateway key, plus ufw on request', function (): void {
        [$process] = image_script('image-check.sh', ['orbit', '1000:1000', '--ufw'], $this->stubs);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    });

    it('fails for another uid, a missing key, an inactive ufw, or an unknown check', function (string $change): void {
        $arguments = ['orbit', '1000:1000', '--ufw'];
        $stubs = $this->stubs;
        match ($change) {
            'uid' => $stubs['id'] = 'echo 1001',
            'key' => unlink($this->work.'/home/.ssh/authorized_keys'),
            'ufw' => $stubs['sudo'] = 'echo "Status: inactive"',
            'check' => $arguments[2] = '--unknown',
        };
        [$process] = image_script('image-check.sh', $arguments, $stubs);

        expect($process->getExitCode())->not->toBe(0);
    })->with(['uid', 'key', 'ufw', 'check']);
});

describe('apt lists lock waiting', function (): void {
    it('waits for a real nonblocking apt lists lock in disposable directories', function (): void {
        $work = sys_get_temp_dir().'/orbit-image-script-'.bin2hex(random_bytes(6));
        mkdir($work.'/lists/partial', 0700, true);
        mkdir($work.'/cache/archives/partial', 0700, true);
        mkdir($work.'/sources', 0700, true);
        file_put_contents($work.'/sources.list', '');
        file_put_contents($work.'/status', '');
        $holder = new Process(['python3', '-c', 'import fcntl,sys,time; f=open(sys.argv[1], "w"); fcntl.lockf(f, fcntl.LOCK_EX); print("locked", flush=True); time.sleep(2)', $work.'/lists/lock']);
        $holder->start();
        try {
            for ($attempt = 0; $attempt < 100 && ! str_contains($holder->getOutput(), 'locked'); $attempt++) {
                usleep(10000);
            }
            expect($holder->getOutput())->toContain('locked');
            $process = new Process(['bash', TaskVmScripts::path('apt-update.sh'), '10',
                '-o', 'Dir::State::lists='.$work.'/lists', '-o', 'Dir::State::status='.$work.'/status',
                '-o', 'Dir::Cache='.$work.'/cache', '-o', 'Dir::Etc::sourcelist='.$work.'/sources.list',
                '-o', 'Dir::Etc::sourceparts='.$work.'/sources']);
            $process->setTimeout(15);
            $process->run();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
                ->and($process->getErrorOutput())->toContain('Could not get lock', '/lists/lock');
        } finally {
            $holder->stop();
        }
    });

    it('fails at the lists-lock deadline and does not retry unrelated failures', function (string $message, string $wait): void {
        [$process, $work] = image_script('apt-update.sh', [$wait], [
            'apt-get' => 'echo attempt >> "$WORK/attempts"; echo '.escapeshellarg($message).' >&2; exit 100',
        ]);
        expect($process->getExitCode())->toBe(100)
            ->and($process->getErrorOutput())->toContain($message)
            ->and(file_get_contents($work.'/attempts'))->toBe("attempt\n");
    })->with([['E: Could not get lock /var/lib/apt/lists/lock', '0'], ['E: The repository has no Release file', '10']]);
});

it('sets public DNS before first boot and restarts the resolver only when configuration changes', function (): void {
    $work = sys_get_temp_dir().'/orbit-image-script-'.bin2hex(random_bytes(6));
    mkdir($work.'/bin', 0755, true);
    file_put_contents($work.'/bin/systemctl', '#!/bin/sh'."\n".'printf "%s\n" "$*" >> "$WORK/calls"');
    chmod($work.'/bin/systemctl', 0755);
    $source = str_replace('/etc/systemd/resolved.conf.d', $work.'/resolved', TaskVmScripts::read('public-dns.sh'));
    foreach ([1, 2] as $attempt) {
        $process = new Process(['bash', '-ceu', $source], env: ['PATH' => $work.'/bin:'.getenv('PATH'), 'WORK' => $work]);
        expect($process->run())->toBe(0, $process->getErrorOutput());
    }
    expect(file_get_contents($work.'/resolved/orbit-public-upstream.conf'))->toBe("[Resolve]\nDNS=1.1.1.1 8.8.8.8\n");
    expect(file($work.'/calls', FILE_IGNORE_NEW_LINES))->toBe(['is-active --quiet systemd-resolved', 'restart systemd-resolved']);
});
