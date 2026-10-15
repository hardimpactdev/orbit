<?php

declare(strict_types=1);

use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

pest()->group('subprocess');

/** The hook the Gateway installed while the renderer lived in core, for this checkout and Orbit home. */
const DocumentsStartHookBytes = <<<'TEXT'
    #!/bin/bash
    set -euo pipefail
    # Preserve the stable lock across service and consumer restarts. Never repair existing state implicitly.
    [ ! -L /run/orbit ]
    if [ ! -e /run/orbit ]; then
        install -d -o root -g root -m 0755 /run/orbit
    fi
    if [ ! -e /run/orbit/project-documents ] && [ ! -L /run/orbit/project-documents ]; then
        mkdir -m 0700 /run/orbit/project-documents
        chown orbit:orbit /run/orbit/project-documents
        install -o orbit -g orbit -m 0600 /dev/null /run/orbit/project-documents/execution.lock
    fi
    exec /usr/sbin/runuser -u orbit -- /usr/bin/env HOME=/home/orbit 'ORBIT_HOME=/home/orbit/.orbit' /usr/bin/php8.5 '/home/orbit/orbit-gateway/artisan' project-documents:cleanup:invalidate --no-interaction

    TEXT;

/** The drop-in the Gateway installed while the renderer lived in core. */
const DocumentsStartUnitBytes = "# Managed by Orbit: document cleanup startup gate\n[Service]\nExecStartPre=+/etc/orbit/project-document-cleanup-start\n";

/**
 * Converges the FPM pool through the container's converger and runs the start hook program it sends in a sandbox,
 * with only its hook directory, drop-in directory, and manifest moved there. `stat` reports the sandbox user's files
 * as root's, so a file that already matches stays untouched as on the Gateway.
 *
 * @param  (Closure(string): void)|null  $before  prepares the sandbox root
 * @return array{string, int, string} the sandbox root, the exit code, and standard error
 */
function documents_start_hook_install(?Closure $before = null): array
{
    config(['orbit.gateway_checkout' => '/home/orbit/orbit-gateway', 'orbit.home' => '/home/orbit/.orbit']);
    $processes = new class implements ProcessRunner
    {
        /** @var list<ProcessInvocation> */
        public array $calls = [];

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->calls[] = $invocation;

            return new CommandResult(0, '', '', 2, false);
        }
    };
    app()->instance(ProcessRunner::class, $processes);
    app(NativeGatewayFpmConverger::class)->converge('/tmp/orbit-gateway-pool.conf');
    $install = Collection::make($processes->calls)->sole(static fn (ProcessInvocation $call): bool => ($call->arguments[4] ?? null) === '/etc/orbit');

    $root = sys_get_temp_dir().'/orbit-start-hook-'.Str::uuid();
    $files = new Filesystem;
    $files->makeDirectory($root.'/bin', 0700, true);
    $files->put($root.'/bin/install', "#!/bin/bash\nargs=()\nwhile (( $# )); do case \"\$1\" in -o|-g) shift 2;; *) args+=(\"\$1\"); shift;; esac; done\nexec /usr/bin/install \"\${args[@]}\"\n");
    $files->put($root.'/bin/chown', "#!/bin/sh\nexit 0\n");
    $files->put($root.'/bin/systemctl', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> '{$root}/systemctl.log'\n[ \"\$1\" = daemon-reload ]\n");
    $files->put($root.'/bin/stat', "#!/bin/bash\nset -o pipefail\n/usr/bin/stat \"\$@\" | sed \"s/^\$(id -un):\$(id -gn):/root:root:/\"\n");
    foreach (['install', 'chown', 'systemctl', 'stat'] as $binary) {
        chmod($root.'/bin/'.$binary, 0700);
    }
    if ($before !== null) {
        $before($root);
    }
    $arguments = array_slice($install->arguments, 1);
    [$arguments[3], $arguments[4], $arguments[5]] = [$root.'/etc/orbit', $root.'/etc/systemd/system/php8.5-fpm.service.d', $root.'/etc/orbit/php-fpm-start-hooks'];
    $previousUmask = umask(0002);
    try {
        $installer = new Process($arguments, env: ['PATH' => $root.'/bin:'.getenv('PATH')]);
        $installer->setInput($install->input);
        $installer->run();
    } finally {
        umask($previousUmask);
    }

    return [$root, $installer->getExitCode() ?? -1, $installer->getErrorOutput()];
}

it('installs the same hook and drop-in bytes as before the move, and only adds the manifest', function (): void {
    [$root, $exitCode, $errors] = documents_start_hook_install();
    try {
        $hook = $root.'/etc/orbit/project-document-cleanup-start';
        $unit = $root.'/etc/systemd/system/php8.5-fpm.service.d/orbit-document-cleanup.conf';
        expect($exitCode)->toBe(0, $errors)
            ->and(array_values(array_diff((array) scandir($root.'/etc/orbit'), ['.', '..'])))->toBe(['php-fpm-start-hooks', 'project-document-cleanup-start'])
            ->and(array_values(array_diff((array) scandir(dirname($unit)), ['.', '..'])))->toBe(['orbit-document-cleanup.conf'])
            ->and(file_get_contents($hook))->toBe(DocumentsStartHookBytes)
            ->and(str_replace($root, '', (string) file_get_contents($unit)))->toBe(DocumentsStartUnitBytes)
            ->and(file_get_contents($root.'/etc/orbit/php-fpm-start-hooks'))->toBe("project-document-cleanup-start orbit-document-cleanup.conf\n")
            ->and(fileperms($hook) & 0o777)->toBe(0o755)
            ->and(fileperms($unit) & 0o777)->toBe(0o644);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('adopts the files the live Gateway has today without changing or deleting them', function (): void {
    $sandboxUnit = static fn (string $root): string => str_replace('/etc/orbit', $root.'/etc/orbit', DocumentsStartUnitBytes);
    [$root, $exitCode, $errors] = documents_start_hook_install(static function (string $root) use ($sandboxUnit): void {
        $files = new Filesystem;
        $files->makeDirectory($root.'/etc/orbit', 0755, true);
        $files->makeDirectory($root.'/etc/systemd/system/php8.5-fpm.service.d', 0755, true);
        $files->put($root.'/etc/orbit/project-document-cleanup-start', DocumentsStartHookBytes);
        $files->put($root.'/etc/systemd/system/php8.5-fpm.service.d/orbit-document-cleanup.conf', $sandboxUnit($root));
        chmod($root.'/etc/orbit/project-document-cleanup-start', 0755);
        chmod($root.'/etc/systemd/system/php8.5-fpm.service.d/orbit-document-cleanup.conf', 0644);
        touch($root.'/etc/orbit/project-document-cleanup-start', 1_700_000_000);
        touch($root.'/etc/systemd/system/php8.5-fpm.service.d/orbit-document-cleanup.conf', 1_700_000_000);
    });
    try {
        $hook = $root.'/etc/orbit/project-document-cleanup-start';
        $unit = $root.'/etc/systemd/system/php8.5-fpm.service.d/orbit-document-cleanup.conf';
        clearstatcache();
        expect($exitCode)->toBe(0, $errors)
            ->and([filemtime($hook), filemtime($unit)])->toBe([1_700_000_000, 1_700_000_000])
            ->and(file_get_contents($hook))->toBe(DocumentsStartHookBytes)
            ->and(file_get_contents($unit))->toBe($sandboxUnit($root))
            ->and(file_get_contents($root.'/etc/orbit/php-fpm-start-hooks'))->toBe("project-document-cleanup-start orbit-document-cleanup.conf\n")
            ->and(file_exists($root.'/systemctl.log'))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});
