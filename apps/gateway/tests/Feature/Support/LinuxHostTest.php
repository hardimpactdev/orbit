<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\SkippedWithMessageException;
use Symfony\Component\Process\Process;
use Tests\Support\LinuxHost;

function linux_host_temporary_directory(): string
{
    $directory = realpath(sys_get_temp_dir()).'/orbit-linux-host-test-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o700);

    return $directory;
}

/** @param list<string> $arguments */
function linux_host_run(array $arguments, ?string $directory = null): Process
{
    $process = new Process($arguments, $directory);
    $process->run();

    return $process;
}

it('builds delegated commands with the toolchain from the copied Gateway', function (): void {
    $directory = linux_host_temporary_directory();
    $files = new Filesystem;

    try {
        $files->ensureDirectoryExists($directory.'/tests');
        $files->ensureDirectoryExists($directory.'/remote-bin');
        $files->put($directory.'/tests/bootstrap.php', <<<'PHP'
            <?php
            namespace Tests\Support;
            final class TestToolchain
            {
                public static function path(string $path): string
                {
                    return getcwd().'/remote-bin:'.$path;
                }
            }
            PHP);
        $files->put($directory.'/remote-bin/timeout', "#!/bin/sh\nprintf '%s\\n' \"\$PATH\" \"\$@\"\n");
        chmod($directory.'/remote-bin/timeout', 0o700);
        $arguments = ['--filter', 'a case with spaces; $literal', 'tests/ExampleTest.php'];

        $process = linux_host_run(['sh', '-c', LinuxHost::testCommand($arguments)], $directory);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(explode("\n", trim($process->getOutput())))->toBe([
                $directory.'/remote-bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                '--kill-after=10', (string) LinuxHost::timeoutSeconds(), 'php', 'vendor/bin/pest', ...$arguments,
            ]);
    } finally {
        $files->deleteDirectory($directory);
    }
});

it('preserves the selected case privilege when building a real Pest delegation', function (bool $privileged): void {
    $arguments = ['tests/Fixtures/linux-host-selection.php', '--colors=never'];

    if ($privileged) {
        $arguments[] = '--group=privileged';
    }

    $process = linux_host_run([PHP_BINARY, 'vendor/bin/pest', ...$arguments], base_path());

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())->toMatch('/Tests:\s+1 passed/');
})->with(['ordinary exclusion' => false, 'explicit privileged selection' => true]);

describe('the Linux test host copy', function (): void {
    it('holds only the files Git would track and vendor', function (): void {
        $root = linux_host_temporary_directory();

        try {
            $files = new Filesystem;
            $files->put("{$root}/.gitignore", "/vendor\n.env\n*.key\n/bootstrap/cache/*.php\n");
            $files->put("{$root}/.env.example", "APP_KEY=\n");
            $files->put("{$root}/tracked.php", '<?php');
            $files->put("{$root}/deleted.php", '<?php');
            linux_host_run(['git', 'init', '--quiet'], $root);
            linux_host_run(['git', 'add', '.'], $root);
            unlink("{$root}/deleted.php");
            $files->put("{$root}/untracked-test.php", '<?php');
            $files->put("{$root}/.env", 'APP_KEY=secret');
            // No ignore rule covers these; the copy leaves them out anyway.
            $files->put("{$root}/.env.local", 'APP_KEY=secret');
            $files->put("{$root}/.env.testing", 'APP_KEY=secret');
            $files->ensureDirectoryExists("{$root}/config");
            $files->put("{$root}/config/.env.production", 'APP_KEY=secret');
            $files->ensureDirectoryExists("{$root}/storage/app");
            $files->put("{$root}/storage/app/nested.key", 'secret');
            $files->ensureDirectoryExists("{$root}/bootstrap/cache");
            $files->put("{$root}/bootstrap/cache/config.php", '<?php return [];');
            $files->ensureDirectoryExists("{$root}/vendor/package");
            $files->put("{$root}/vendor/package/code.php", '<?php');

            $paths = LinuxHost::syncPaths($root);
            sort($paths);

            expect($paths)->toBe(['.env.example', '.gitignore', 'tracked.php', 'untracked-test.php', 'vendor']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('removes only copies whose directory and marker are both stale, in private directories', function (): void {
        $base = linux_host_temporary_directory().'/base';
        $old = '200001010000';

        try {
            mkdir($base, 0o755);
            foreach (['abandoned', 'busy', 'unmarked-old', 'starting'] as $copy) {
                mkdir("{$base}/{$copy}");
            }
            foreach (['abandoned', 'busy'] as $copy) {
                touch("{$base}/{$copy}/alive");
            }
            linux_host_run(['touch', '-t', $old, "{$base}/abandoned/alive"]);
            // rsync gives a copy the source's old modification time; only the marker says it is in use.
            foreach (['abandoned', 'busy', 'unmarked-old'] as $copy) {
                linux_host_run(['touch', '-t', $old, "{$base}/{$copy}"]);
            }

            $prepare = linux_host_run(['sh', '-c', LinuxHost::prepareScript(escapeshellarg($base), 'fresh', dirname($base).'/legacy')]);

            expect($prepare->getExitCode())->toBe(0, $prepare->getErrorOutput())
                ->and(trim($prepare->getOutput()))->toBe("{$base}/fresh")
                ->and(is_dir("{$base}/abandoned"))->toBeFalse()
                ->and(is_dir("{$base}/unmarked-old"))->toBeFalse()
                ->and(is_dir("{$base}/busy"))->toBeTrue()
                ->and(is_dir("{$base}/starting"))->toBeTrue()
                ->and("{$base}/fresh/alive")->toBeFile()
                ->and(fileperms($base) & 0o777)->toBe(0o700)
                ->and(fileperms("{$base}/fresh") & 0o777)->toBe(0o700);
        } finally {
            new Filesystem()->deleteDirectory(dirname($base));
        }
    });

    it('keeps a recently synced copy in the earlier shared directory and removes the directory once it is empty', function (): void {
        $directory = linux_host_temporary_directory();
        $legacy = "{$directory}/legacy";

        try {
            mkdir("{$legacy}/in-use", 0o755, true);
            // rsync gave the copy the source's old modification time; its change time is recent.
            linux_host_run(['touch', '-t', '200001010000', "{$legacy}/in-use"]);

            $first = linux_host_run(['sh', '-c', LinuxHost::prepareScript(escapeshellarg("{$directory}/base"), 'one', $legacy)]);

            expect($first->getExitCode())->toBe(0, $first->getErrorOutput())
                ->and(is_dir("{$legacy}/in-use"))->toBeTrue();

            rmdir("{$legacy}/in-use");
            linux_host_run(['sh', '-c', LinuxHost::prepareScript(escapeshellarg("{$directory}/base"), 'two', $legacy)]);

            expect(is_dir($legacy))->toBeFalse();
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('removes copies in the earlier shared directory that have not changed for the stale period', function (): void {
        // BSD find rounds -cmin ages differently, so the zero-minute case runs on Linux.
        if (LinuxHost::delegate($this)) {
            return;
        }

        $directory = linux_host_temporary_directory();
        $legacy = "{$directory}/legacy";

        try {
            mkdir("{$legacy}/abandoned", 0o755, true);
            sleep(2);

            $prepare = linux_host_run(['sh', '-c', LinuxHost::prepareScript(escapeshellarg("{$directory}/base"), 'fresh', $legacy, 0)]);

            expect($prepare->getExitCode())->toBe(0, $prepare->getErrorOutput())
                ->and(is_dir("{$legacy}/abandoned"))->toBeFalse()
                ->and(is_dir($legacy))->toBeFalse()
                ->and("{$directory}/base/fresh/alive")->toBeFile();
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('refuses a base directory that is a symbolic link', function (): void {
        $directory = linux_host_temporary_directory();

        try {
            mkdir("{$directory}/elsewhere", 0o700);
            symlink("{$directory}/elsewhere", "{$directory}/base");

            $prepare = linux_host_run(['sh', '-c', LinuxHost::prepareScript(escapeshellarg("{$directory}/base"), 'fresh')]);

            expect($prepare->getExitCode())->not->toBe(0)
                ->and($prepare->getErrorOutput())->toContain('is not a directory that this account owns')
                ->and(is_dir("{$directory}/elsewhere/fresh"))->toBeFalse();
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('stops the test that runs from a copy and nothing else', function (): void {
        $directory = linux_host_temporary_directory();

        try {
            foreach (['copy', 'other'] as $copy) {
                mkdir("{$directory}/{$copy}/gateway/vendor/bin", 0o700, true);
                file_put_contents("{$directory}/{$copy}/gateway/vendor/bin/pest", "sleep 30\n");
            }
            $running = new Process(['sh', "{$directory}/copy/gateway/vendor/bin/pest"]);
            $other = new Process(['sh', "{$directory}/other/gateway/vendor/bin/pest"]);
            $running->start();
            $other->start();
            usleep(200_000);

            $stop = linux_host_run(['sh', '-c', LinuxHost::stopScript("{$directory}/copy")]);
            $deadline = microtime(true) + 5;
            while ($running->isRunning() && microtime(true) < $deadline) {
                usleep(50_000);
            }

            expect($stop->getExitCode())->toBe(0)
                ->and($running->isRunning())->toBeFalse()
                ->and($other->isRunning())->toBeTrue();
            $other->stop(0);
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('stops a test after five minutes unless ORBIT_LINUX_TEST_TIMEOUT sets another positive number of seconds', function (): void {
        $previous = getenv(LinuxHost::TimeoutVariable);

        try {
            putenv(LinuxHost::TimeoutVariable);
            expect(LinuxHost::timeoutSeconds())->toBe(300);
            putenv(LinuxHost::TimeoutVariable.'=5');
            expect(LinuxHost::timeoutSeconds())->toBe(5);
            putenv(LinuxHost::TimeoutVariable.'=0');
            expect(LinuxHost::timeoutSeconds())->toBe(300);
            putenv(LinuxHost::TimeoutVariable.'=soon');
            expect(LinuxHost::timeoutSeconds())->toBe(300);
        } finally {
            putenv($previous === false ? LinuxHost::TimeoutVariable : LinuxHost::TimeoutVariable.'='.$previous);
        }
    });

    it('uses the host that ORBIT_LINUX_TEST_HOST names and skips the test when it names none', function (): void {
        $previous = getenv(LinuxHost::HostVariable);

        try {
            putenv(LinuxHost::HostVariable.'=linux-builder');
            expect(LinuxHost::host())->toBe('linux-builder')
                ->and(LinuxHost::requireHost())->toBe('linux-builder');

            foreach ([LinuxHost::HostVariable, LinuxHost::HostVariable.'='] as $unset) {
                putenv($unset);
                expect(LinuxHost::host())->toBeNull()
                    ->and(fn (): string => LinuxHost::requireHost())
                    ->toThrow(SkippedWithMessageException::class, 'set ORBIT_LINUX_TEST_HOST to a Linux SSH host');
            }
        } finally {
            putenv($previous === false ? LinuxHost::HostVariable : LinuxHost::HostVariable.'='.$previous);
        }
    });
});
