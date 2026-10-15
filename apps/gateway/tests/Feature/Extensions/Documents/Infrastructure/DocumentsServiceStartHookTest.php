<?php

declare(strict_types=1);

use App\Domain\Extensions\Contributions;
use App\Domain\Gateway\GatewayServiceStartHook;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Orbit\Extensions\Documents\Infrastructure\DocumentsServiceStartHook;
use Symfony\Component\Process\Process;

pest()->group('subprocess');

it('installs the same hook and drop-in bytes as before the move, and no other file', function (): void {
    // The bytes the Gateway installed while the renderer lived in core, for this checkout and Orbit home.
    $hook = <<<'TEXT'
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
    $unit = "# Managed by Orbit: document cleanup startup gate\n[Service]\nExecStartPre=+/etc/orbit/project-document-cleanup-start\n";
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
    $hooks = app(Contributions::class)->of(GatewayServiceStartHook::class);
    expect($hooks)->toHaveCount(1)->and($hooks[0])->toBeInstanceOf(DocumentsServiceStartHook::class);

    new NativeGatewayFpmConverger($processes, $hooks)->converge('/tmp/orbit-gateway-pool.conf');

    $install = Collection::make($processes->calls)->sole(static fn (ProcessInvocation $call): bool => $call->arguments === ['sudo', 'bash', '-seu']);
    $root = sys_get_temp_dir().'/orbit-start-hook-'.Str::uuid();
    $filesystem = new Filesystem;
    $filesystem->makeDirectory($root.'/bin', 0700, true);
    $filesystem->put($root.'/bin/install', "#!/bin/bash\nargs=()\nwhile (( $# )); do case \"\$1\" in -o|-g) shift 2;; *) args+=(\"\$1\"); shift;; esac; done\nexec /usr/bin/install \"\${args[@]}\"\n");
    $filesystem->put($root.'/bin/chown', "#!/bin/sh\nexit 0\n");
    $filesystem->put($root.'/bin/systemctl', "#!/bin/sh\n[ \"\$1\" = daemon-reload ]\n");
    foreach (['install', 'chown', 'systemctl'] as $binary) {
        chmod($root.'/bin/'.$binary, 0700);
    }
    $previousUmask = umask(0002);
    try {
        $installer = new Process(['bash', '-seu'], env: ['PATH' => $root.'/bin:'.getenv('PATH')]);
        $installer->setInput(str_replace(['/etc/orbit', '/etc/systemd'], [$root.'/etc/orbit', $root.'/etc/systemd'], (string) $install->input));
        expect($installer->run())->toBe(0, $installer->getErrorOutput());

        $hookPath = $root.'/etc/orbit/project-document-cleanup-start';
        $unitPath = $root.'/etc/systemd/system/php8.5-fpm.service.d/orbit-document-cleanup.conf';
        expect(array_values(array_diff((array) scandir($root.'/etc/orbit'), ['.', '..'])))->toBe(['project-document-cleanup-start'])
            ->and(array_values(array_diff((array) scandir(dirname($unitPath)), ['.', '..'])))->toBe(['orbit-document-cleanup.conf'])
            ->and(file_get_contents($hookPath))->toBe($hook)
            ->and(str_replace($root, '', (string) file_get_contents($unitPath)))->toBe($unit)
            ->and(fileperms($hookPath) & 0o777)->toBe(0o755)
            ->and(fileperms($unitPath) & 0o777)->toBe(0o644);
    } finally {
        umask($previousUmask);
        $filesystem->deleteDirectory($root);
    }
});
