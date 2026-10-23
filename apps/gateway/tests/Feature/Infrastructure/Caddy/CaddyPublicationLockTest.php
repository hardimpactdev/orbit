<?php

declare(strict_types=1);

use App\Infrastructure\Caddy\CaddyPublicationLock;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('keeps Caddy publication and reads behind agent commands', function (): void {
    foreach (['NodeCaddyBuilder', 'NodeCaddyLiveReader', 'NodeCaddyTransport'] as $class) {
        $source = file_get_contents(app_path("Infrastructure/Caddy/Build/{$class}.php"));
        expect($source)->not->toContain('SshExecutor', 'ProcessRunner', 'RemoteCommand', 'bash');
    }
});

describe('the shared Caddy lock program', function (): void {
    it('creates a private lock directory and file and takes the lock', function (): void {
        $root = caddy_lock_directory();

        try {
            $result = caddy_lock_run($root);

            expect($result->getExitCode())->toBe(0)
                ->and($result->getOutput())->toBe("locked\n")
                ->and(fileperms($root.'/orbit') & 0o777)->toBe(0o700)
                ->and(fileperms($root.'/orbit/caddy.lock') & 0o777)->toBe(0o600);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('refuses a lock directory other users can enter', function (): void {
        $root = caddy_lock_directory();
        mkdir($root.'/orbit', 0o755);
        chmod($root.'/orbit', 0o755);

        try {
            $result = caddy_lock_run($root);

            expect($result->getExitCode())->not->toBe(0)
                ->and($result->getOutput())->toBe('');
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('refuses a symlinked lock file', function (): void {
        $root = caddy_lock_directory();
        mkdir($root.'/orbit', 0o700);
        touch($root.'/elsewhere');
        symlink($root.'/elsewhere', $root.'/orbit/caddy.lock');

        try {
            $result = caddy_lock_run($root);

            expect($result->getExitCode())->not->toBe(0)
                ->and($result->getOutput())->toBe('');
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });
});

function caddy_lock_directory(): string
{
    $root = sys_get_temp_dir().'/orbit-caddy-lock-'.bin2hex(random_bytes(8));
    mkdir($root, 0o700);

    return $root;
}

/** Runs the lock program with the hardened checks aimed at a temporary lock owned by this user. */
function caddy_lock_run(string $root): Process
{
    $lock = $root.'/orbit/caddy.lock';
    $owner = posix_geteuid().':'.posix_getegid();
    $program = str_replace(
        [CaddyPublicationLock::Path, '0:0'],
        [$lock, $owner],
        CaddyPublicationLock::script($lock),
    );
    $process = new Process(['bash', '-seu']);
    $process->setInput($program."\necho locked\n");
    $process->run();

    return $process;
}
