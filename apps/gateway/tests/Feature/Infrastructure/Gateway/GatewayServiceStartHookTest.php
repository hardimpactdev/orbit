<?php

declare(strict_types=1);

use App\Domain\AgentView\AgentViewConverger;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Fleet\FleetConvergeUnits;
use App\Domain\Gateway\GatewayServiceStartHook;
use App\Domain\Gateway\GatewayWebConverger;
use App\Domain\GatewayReleases\GatewayReleaseUnitConverger;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildResult;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\GatewayReleases\GatewayRuntimeHandoff;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function start_hook(string $name, string $dropIn, string $description = 'test start hook', string $program = "#!/bin/bash\nexit 0\n"): GatewayServiceStartHook
{
    return new readonly class($name, $dropIn, $description, $program) implements GatewayServiceStartHook
    {
        public function __construct(
            private string $name,
            private string $dropIn,
            private string $description,
            private string $program,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function dropIn(): string
        {
            return $this->dropIn;
        }

        public function description(): string
        {
            return $this->description;
        }

        public function renderHook(string $checkoutPath, string $orbitHome): string
        {
            return $this->program;
        }
    };
}

/**
 * Records each command and answers it with success, or with what `$answer` returns for it.
 *
 * @param  (Closure(ProcessInvocation): ?CommandResult)|null  $answer
 * @return ProcessRunner&object{calls: list<ProcessInvocation>}
 */
function start_hook_runner(?Closure $answer = null): ProcessRunner
{
    return new class($answer) implements ProcessRunner
    {
        /** @var list<ProcessInvocation> */
        public array $calls = [];

        public function __construct(private readonly ?Closure $answer) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->calls[] = $invocation;
            $arguments = $invocation->arguments;
            // The Gateway certificate publisher compares the key and the certificate by their public keys.
            if (($arguments[1] ?? null) === 'openssl' && array_intersect(['-pubout', '-pubkey'], $arguments) !== []) {
                return new CommandResult(0, "PUBLIC KEY\n", '', 2, false);
            }

            return ($this->answer === null ? null : ($this->answer)($invocation)) ?? new CommandResult(0, '', '', 2, false);
        }
    };
}

/** @param list<GatewayServiceStartHook> $hooks */
function start_hook_converger(ProcessRunner $runner, array $hooks): NativeGatewayFpmConverger
{
    return new NativeGatewayFpmConverger($runner, app(RemoteProgramCatalog::class), $hooks);
}

/**
 * The start hook installs, the hook runs, and the pool reload, in order.
 *
 * @param  list<ProcessInvocation>  $calls
 * @return list<string>
 */
function start_hook_steps(array $calls): array
{
    $steps = [];
    foreach ($calls as $call) {
        $arguments = $call->arguments;
        if (array_slice($arguments, 0, 5) === ['sudo', 'bash', '-seu', '--', NativeGatewayFpmConverger::START_HOOK_DIRECTORY]) {
            $steps[] = trim('install '.implode(' ', array_filter(array_slice($arguments, 7), static fn (int $index): bool => $index % 4 === 0, ARRAY_FILTER_USE_KEY)));
        } elseif (count($arguments) === 2 && str_starts_with($arguments[1], NativeGatewayFpmConverger::START_HOOK_DIRECTORY.'/')) {
            $steps[] = 'run '.basename($arguments[1]);
        } elseif ($arguments === ['sudo', 'systemctl', 'reload-or-restart', 'php8.5-fpm']) {
            $steps[] = 'reload';
        }
    }

    return $steps;
}

/**
 * A sandbox with stand-ins for the commands that need root. Its `stat` reports the sandbox user's files as root's. Its
 * `systemctl` logs each daemon-reload with the drop-ins and programs that exist then, fails one reload while the file
 * `fail-reload` exists, and reports `NeedDaemonReload` from the file `need-reload`.
 */
function start_hook_sandbox(): string
{
    $root = sys_get_temp_dir().'/orbit-start-hooks-'.Str::uuid();
    $files = new Filesystem;
    $files->makeDirectory($root.'/bin', 0700, true);
    $files->put($root.'/bin/install', "#!/bin/bash\nargs=()\nwhile (( $# )); do case \"\$1\" in -o|-g) shift 2;; *) args+=(\"\$1\"); shift;; esac; done\nexec /usr/bin/install \"\${args[@]}\"\n");
    $files->put($root.'/bin/chown', "#!/bin/sh\nexit 0\n");
    $files->put($root.'/bin/systemctl', str_replace('ROOT', $root, <<<'SYSTEMCTL'
        #!/bin/sh
        case "$*" in
        daemon-reload)
            if [ -e ROOT/fail-reload ]; then
                rm -f ROOT/fail-reload
                exit 1
            fi
            printf 'daemon-reload drop-ins: %s programs: %s\n' "$(ls ROOT/etc/systemd/system/php8.5-fpm.service.d | tr '\n' ' ')" "$(ls ROOT/etc/orbit | tr '\n' ' ')" >> ROOT/systemctl.log ;;
        'show --property=NeedDaemonReload --value php8.5-fpm.service')
            cat ROOT/need-reload 2>/dev/null || echo no ;;
        *)
            exit 1 ;;
        esac

        SYSTEMCTL));
    $files->put($root.'/bin/stat', "#!/bin/bash\nset -o pipefail\n/usr/bin/stat \"\$@\" | sed \"s/^\$(id -un):\$(id -gn):/root:root:/\"\n");
    foreach (['install', 'chown', 'systemctl', 'stat'] as $binary) {
        chmod($root.'/bin/'.$binary, 0700);
    }

    return $root;
}

/**
 * Runs the program that `installStartHooks()` sends, with its hook directory, drop-in directory, and manifest in the
 * sandbox.
 *
 * @param  list<GatewayServiceStartHook>  $hooks
 * @return array{int, string} the exit code and standard error
 */
function run_start_hook_program(string $root, array $hooks): array
{
    $runner = start_hook_runner();
    start_hook_converger($runner, $hooks)->installStartHooks();
    $install = $runner->calls[0];
    $arguments = array_slice($install->arguments, 1);
    [$arguments[3], $arguments[4], $arguments[5]] = [$root.'/etc/orbit', $root.'/etc/systemd/system/php8.5-fpm.service.d', $root.'/etc/orbit/php-fpm-start-hooks'];
    $process = new Process($arguments, env: ['PATH' => $root.'/bin:'.getenv('PATH')]);
    $process->setInput($install->input);
    $process->run();

    return [$process->getExitCode() ?? -1, $process->getErrorOutput()];
}

/**
 * Every file under the sandbox's /etc, with the sandbox root taken out of paths and contents.
 *
 * @return array<string, string>
 */
function start_hook_files(string $root): array
{
    $files = [];
    foreach (new Filesystem()->allFiles($root.'/etc', true) as $file) {
        $files[str_replace($root, '', $file->getPathname())] = str_replace($root, '', $file->getContents());
    }
    ksort($files);

    return $files;
}

function last_start_hook_reload(string $root): string
{
    $lines = array_filter(explode("\n", (string) @file_get_contents($root.'/systemctl.log')));

    return (string) end($lines);
}

function start_hook_reloads(string $root): int
{
    return substr_count((string) @file_get_contents($root.'/systemctl.log'), 'daemon-reload ');
}

/** @return array<string, string> the drop-in a hook gets, by its path below the sandbox */
function start_hook_unit(string $name, string $dropIn): array
{
    return ["/etc/systemd/system/php8.5-fpm.service.d/{$dropIn}" => "# Managed by Orbit: test start hook\n[Service]\nExecStartPre=+/etc/orbit/{$name}\n"];
}

describe('the start hook contract', function (): void {
    it('installs every contributed hook, then runs each one before the pool reload', function (): void {
        $runner = start_hook_runner();

        start_hook_converger($runner, [start_hook('first-start', 'orbit-first.conf'), start_hook('second-start', 'orbit-second.conf')])
            ->converge('/tmp/orbit-gateway-pool.conf');

        expect(start_hook_steps($runner->calls))->toBe(['install first-start second-start', 'run first-start', 'run second-start', 'reload']);
    });

    it('keeps every core path in /etc/orbit outside the start hook names', function (): void {
        $paths = [];
        foreach (['app', 'config', 'resources', 'routes'] as $directory) {
            foreach (new Filesystem()->allFiles(base_path($directory)) as $file) {
                preg_match_all('~/etc/orbit/([A-Za-z0-9._-]+)~', $file->getContents(), $matches);
                array_push($paths, ...$matches[1]);
            }
        }
        $paths = array_values(array_unique($paths));

        expect($paths)->toContain('metrics', 'agent', 'service-metrics', 'php-fpm-start-hooks')
            ->and(array_values(array_filter($paths, static fn (string $name): bool => preg_match(NativeGatewayFpmConverger::START_HOOK_NAME, $name) === 1)))->toBe([]);
    });

    it('runs the install program without a hook too, so it can remove the hooks it installed before', function (): void {
        $runner = start_hook_runner();

        start_hook_converger($runner, [])->converge('/tmp/orbit-gateway-pool.conf');

        expect(start_hook_steps($runner->calls))->toBe(['install', 'reload']);
    });

    it('refuses a hook that breaks the contract before it changes anything', function (array $hooks, string $problem): void {
        $runner = start_hook_runner();

        expect(fn () => start_hook_converger($runner, $hooks)->converge('/tmp/orbit-gateway-pool.conf'))
            ->toThrow(function (NodeProvisioningException $exception) use ($problem): void {
                expect([$exception->step, $exception->errorCode])->toBe(['gateway-fpm-start-hook-validate', 'gateway.fpm_config_invalid'])
                    ->and($exception->getMessage())->toContain($problem);
            })
            ->and($runner->calls)->toBe([]);
    })->with([
        'the core directory metrics' => [[start_hook('metrics', 'orbit-test.conf')], 'a program name that does not end in -start'],
        'the core directory agent' => [[start_hook('agent', 'orbit-test.conf')], 'a program name that does not end in -start'],
        'the core directory service-metrics' => [[start_hook('service-metrics', 'orbit-test.conf')], 'a program name that does not end in -start'],
        'a path outside the directory' => [[start_hook('../escape-start', 'orbit-test.conf')], 'a program name that does not end in -start'],
        'a drop-in systemd ignores' => [[start_hook('test-start', 'orbit-test.cfg')], 'a drop-in that is not orbit-*.conf'],
        'a drop-in without the orbit prefix' => [[start_hook('test-start', 'test.conf')], 'a drop-in that is not orbit-*.conf'],
        "core's VPN ordering drop-in" => [[start_hook('test-start', 'orbit-vpn.conf')], 'the drop-in [orbit-vpn.conf] that core writes'],
        'a description that adds a directive' => [[start_hook('test-start', 'orbit-test.conf', "gate\n[Service]\nExecStartPre=+/bin/false")], 'a description that is not one printable line'],
        'an empty description' => [[start_hook('test-start', 'orbit-test.conf', '')], 'a description that is not one printable line'],
        'a program name another hook uses' => [[start_hook('test-start', 'orbit-one.conf'), start_hook('test-start', 'orbit-two.conf')], 'the program name [test-start] of another hook'],
        'a drop-in another hook uses' => [[start_hook('one-start', 'orbit-test.conf'), start_hook('two-start', 'orbit-test.conf')], 'the drop-in [orbit-test.conf] of another hook'],
    ]);

    it('names the hook the install program was working on when it failed', function (): void {
        $runner = start_hook_runner(static fn (ProcessInvocation $call): ?CommandResult => ($call->arguments[4] ?? null) === NativeGatewayFpmConverger::START_HOOK_DIRECTORY
            ? new CommandResult(1, '', "orbit-start-hook=first-start\norbit-start-hook=second-start\nmv: cannot overwrite directory\n", 2, false)
            : null);

        expect(fn () => start_hook_converger($runner, [start_hook('first-start', 'orbit-first.conf'), start_hook('second-start', 'orbit-second.conf')])->converge('/tmp/orbit-gateway-pool.conf'))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect([$exception->step, $exception->errorCode, $exception->getMessage()])
                    ->toBe(['gateway-fpm-start-hook-install', 'gateway.fpm_config_install_failed', 'Gateway FPM start hook [second-start] could not be installed or removed.']);
            })
            ->and(start_hook_steps($runner->calls))->toBe(['install first-start second-start']);
    });

    it('names no hook when a step for every hook failed', function (): void {
        $runner = start_hook_runner(static fn (ProcessInvocation $call): ?CommandResult => ($call->arguments[4] ?? null) === NativeGatewayFpmConverger::START_HOOK_DIRECTORY
            ? new CommandResult(1, '', "orbit-start-hook=first-start\norbit-start-hook=\nThe start hook manifest holds an invalid line.\n", 2, false)
            : null);

        expect(fn () => start_hook_converger($runner, [start_hook('first-start', 'orbit-first.conf')])->installStartHooks())
            ->toThrow(NodeProvisioningException::class, 'The Gateway FPM start hooks could not be installed.');
    });

    it('names the hook that failed to run, and does not reload the pool', function (): void {
        $runner = start_hook_runner(static fn (ProcessInvocation $call): ?CommandResult => $call->arguments === ['sudo', '/etc/orbit/second-start']
            ? new CommandResult(1, '', 'state unavailable', 2, false)
            : null);

        expect(fn () => start_hook_converger($runner, [start_hook('first-start', 'orbit-first.conf'), start_hook('second-start', 'orbit-second.conf')])->converge('/tmp/orbit-gateway-pool.conf'))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect([$exception->step, $exception->errorCode, $exception->getMessage()])
                    ->toBe(['gateway-fpm-start-hook', 'gateway.fpm_start_failed', 'Gateway FPM start hook [second-start] failed.']);
            })
            ->and(start_hook_steps($runner->calls))->toBe(['install first-start second-start', 'run first-start', 'run second-start']);
    });
});

describe('the install program', function (): void {
    it('adopts the files of a Gateway without a manifest and removes nothing', function (): void {
        $root = start_hook_sandbox();
        try {
            $files = new Filesystem;
            $files->makeDirectory($root.'/etc/orbit', 0711, true);
            chmod($root.'/etc/orbit', 0711);
            $files->makeDirectory($root.'/etc/systemd/system/php8.5-fpm.service.d', 0755, true);
            $program = $root.'/etc/orbit/first-start';
            $unit = $root.'/etc/systemd/system/php8.5-fpm.service.d/orbit-first.conf';
            $files->put($program, "#!/bin/bash\nexit 0\n");
            $files->put($unit, "# Managed by Orbit: test start hook\n[Service]\nExecStartPre=+{$root}/etc/orbit/first-start\n");
            chmod($program, 0755);
            chmod($unit, 0644);
            // A hook from before the manifest that nothing contributes now. Core cannot tell it is its own.
            $files->put($root.'/etc/orbit/older-start', "#!/bin/bash\nexit 0\n");
            $files->put($root.'/etc/systemd/system/php8.5-fpm.service.d/orbit-older.conf', "[Service]\n");
            $before = [fileinode($program), filemtime($program), fileinode($unit), filemtime($unit)];

            [$exitCode, $errors] = run_start_hook_program($root, [start_hook('first-start', 'orbit-first.conf')]);

            clearstatcache();
            expect($exitCode)->toBe(0, $errors)
                ->and([fileinode($program), filemtime($program), fileinode($unit), filemtime($unit)])->toBe($before)
                ->and(fileperms($root.'/etc/orbit') & 0o777)->toBe(0o711)
                ->and(start_hook_files($root))->toBe([
                    '/etc/orbit/first-start' => "#!/bin/bash\nexit 0\n",
                    '/etc/orbit/older-start' => "#!/bin/bash\nexit 0\n",
                    '/etc/orbit/php-fpm-start-hooks' => "first-start orbit-first.conf\n",
                    ...start_hook_unit('first-start', 'orbit-first.conf'),
                    '/etc/systemd/system/php8.5-fpm.service.d/orbit-older.conf' => "[Service]\n",
                ])
                ->and(start_hook_reloads($root))->toBe(0);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('removes a hook that is no longer contributed, drop-in first, with one reload', function (): void {
        $root = start_hook_sandbox();
        try {
            run_start_hook_program($root, [start_hook('older-start', 'orbit-older.conf'), start_hook('kept-start', 'orbit-kept.conf')]);
            $reloads = start_hook_reloads($root);

            [$exitCode, $errors] = run_start_hook_program($root, [start_hook('kept-start', 'orbit-kept.conf')]);

            expect($exitCode)->toBe(0, $errors)
                ->and(start_hook_files($root))->toBe([
                    '/etc/orbit/kept-start' => "#!/bin/bash\nexit 0\n",
                    '/etc/orbit/php-fpm-start-hooks' => "kept-start orbit-kept.conf\n",
                    ...start_hook_unit('kept-start', 'orbit-kept.conf'),
                ])
                ->and(start_hook_reloads($root) - $reloads)->toBe(1)
                // systemd reloads after the drop-in is gone and before the program goes.
                ->and(last_start_hook_reload($root))->toBe('daemon-reload drop-ins: orbit-kept.conf  programs: kept-start older-start php-fpm-start-hooks ');

            [$exitCode, $errors] = run_start_hook_program($root, []);

            expect($exitCode)->toBe(0, $errors)
                ->and(start_hook_files($root))->toBe(['/etc/orbit/php-fpm-start-hooks' => ''])
                ->and(start_hook_reloads($root) - $reloads)->toBe(2);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('follows a renamed program and a renamed drop-in', function (): void {
        $root = start_hook_sandbox();
        try {
            run_start_hook_program($root, [start_hook('first-start', 'orbit-first.conf')]);

            [$exitCode, $errors] = run_start_hook_program($root, [start_hook('renamed-start', 'orbit-first.conf')]);

            expect($exitCode)->toBe(0, $errors)
                ->and(start_hook_files($root))->toBe([
                    '/etc/orbit/php-fpm-start-hooks' => "renamed-start orbit-first.conf\n",
                    '/etc/orbit/renamed-start' => "#!/bin/bash\nexit 0\n",
                    ...start_hook_unit('renamed-start', 'orbit-first.conf'),
                ])
                ->and(start_hook_reloads($root))->toBe(2);

            [$exitCode, $errors] = run_start_hook_program($root, [start_hook('renamed-start', 'orbit-renamed.conf')]);

            expect($exitCode)->toBe(0, $errors)
                ->and(start_hook_files($root))->toBe([
                    '/etc/orbit/php-fpm-start-hooks' => "renamed-start orbit-renamed.conf\n",
                    '/etc/orbit/renamed-start' => "#!/bin/bash\nexit 0\n",
                    ...start_hook_unit('renamed-start', 'orbit-renamed.conf'),
                ])
                ->and(start_hook_reloads($root))->toBe(3);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('changes no file and does not reload on a second run', function (): void {
        $root = start_hook_sandbox();
        try {
            $hooks = [start_hook('first-start', 'orbit-first.conf')];
            run_start_hook_program($root, $hooks);
            $paths = [$root.'/etc/orbit/first-start', $root.'/etc/orbit/php-fpm-start-hooks', $root.'/etc/systemd/system/php8.5-fpm.service.d/orbit-first.conf'];
            $before = array_map(static fn (string $path): array => [fileinode($path), filemtime($path)], $paths);

            [$exitCode, $errors] = run_start_hook_program($root, $hooks);

            clearstatcache();
            expect($exitCode)->toBe(0, $errors)
                ->and(array_map(static fn (string $path): array => [fileinode($path), filemtime($path)], $paths))->toBe($before)
                ->and(start_hook_reloads($root))->toBe(1);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('reloads on a retry after a failed reload, before it removes the program', function (): void {
        $root = start_hook_sandbox();
        try {
            run_start_hook_program($root, [start_hook('older-start', 'orbit-older.conf'), start_hook('kept-start', 'orbit-kept.conf')]);
            touch($root.'/fail-reload');

            [$failed] = run_start_hook_program($root, [start_hook('kept-start', 'orbit-kept.conf')]);
            $afterFailure = start_hook_files($root);
            [$exitCode, $errors] = run_start_hook_program($root, [start_hook('kept-start', 'orbit-kept.conf')]);

            expect($failed)->not->toBe(0)
                ->and($afterFailure)->toHaveKey('/etc/orbit/older-start')
                ->and($afterFailure)->not->toHaveKey('/etc/systemd/system/php8.5-fpm.service.d/orbit-older.conf')
                ->and($exitCode)->toBe(0, $errors)
                ->and(last_start_hook_reload($root))->toBe('daemon-reload drop-ins: orbit-kept.conf  programs: kept-start older-start php-fpm-start-hooks ')
                ->and(start_hook_files($root))->not->toHaveKey('/etc/orbit/older-start');
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('reloads when systemd reports a changed unit, though this run wrote nothing', function (): void {
        $root = start_hook_sandbox();
        try {
            $hooks = [start_hook('first-start', 'orbit-first.conf')];
            run_start_hook_program($root, $hooks);
            file_put_contents($root.'/need-reload', "yes\n");

            [$exitCode, $errors] = run_start_hook_program($root, $hooks);

            expect($exitCode)->toBe(0, $errors)->and(start_hook_reloads($root))->toBe(2);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('never moves a hook into a directory that holds its name', function (): void {
        $root = start_hook_sandbox();
        try {
            new Filesystem()->makeDirectory($root.'/etc/orbit/first-start', 0755, true);
            file_put_contents($root.'/etc/orbit/first-start/kept', 'kept');

            [$exitCode, $errors] = run_start_hook_program($root, [start_hook('first-start', 'orbit-first.conf')]);

            preg_match_all('/^orbit-start-hook=(\S*)$/m', $errors, $markers);
            expect($exitCode)->not->toBe(0)
                ->and(end($markers[1]))->toBe('first-start')
                ->and(start_hook_files($root))->toBe([
                    '/etc/orbit/first-start/kept' => 'kept',
                    '/etc/orbit/php-fpm-start-hooks' => "first-start orbit-first.conf\n",
                ])
                ->and(glob($root.'/etc/orbit/.first-start.*'))->toBe([]);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('stops on a manifest line it cannot trust and removes nothing', function (): void {
        $root = start_hook_sandbox();
        try {
            $files = new Filesystem;
            $files->makeDirectory($root.'/etc/orbit', 0755, true);
            $files->put($root.'/etc/orbit/php-fpm-start-hooks', "../../escape-start orbit-escape.conf\n");
            $files->put($root.'/etc/orbit/first-start', 'first');

            [$exitCode, $errors] = run_start_hook_program($root, []);

            expect($exitCode)->not->toBe(0)
                ->and($errors)->toContain('holds an invalid line')
                ->and(start_hook_files($root))->toBe([
                    '/etc/orbit/first-start' => 'first',
                    '/etc/orbit/php-fpm-start-hooks' => "../../escape-start orbit-escape.conf\n",
                ]);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });
});

describe('production wiring', function (): void {
    beforeEach(function (): void {
        $home = sys_get_temp_dir().'/orbit-start-hook-home-'.Str::uuid();
        mkdir($home.'/ca/gateway-current', 0o700, true);
        file_put_contents($home.'/ca/gateway-current/gateway.key', 'PRIVATE KEY');
        file_put_contents($home.'/ca/gateway-current/gateway.pem', 'CERTIFICATE');
        config(['orbit.home' => $home, 'orbit.gateway_checkout' => '/home/orbit/orbit-gateway', 'orbit.gateway_web' => '/home/orbit/web']);
        $this->home = $home;
        $this->runner = start_hook_runner();
        $this->app->instance(ProcessRunner::class, $this->runner);
        $this->app->instance(GatewayCertificateIssuer::class, new readonly class($home) implements GatewayCertificateIssuer
        {
            public function __construct(private string $home) {}

            public function issue(string $hostname, string $wireguardIp): GatewayCertificatePaths
            {
                return new GatewayCertificatePaths($this->home.'/ca/gateway-current/gateway.key', $this->home.'/ca/gateway-current/gateway.pem');
            }
        });
        $this->app->instance(NodeCaddyBuilds::class, new class implements NodeCaddyBuilds
        {
            public function build(Node $node): NodeCaddyBuildResult
            {
                return NodeCaddyBuildResult::Published;
            }

            public function checkListenAddresses(Node $node): void {}
        });
        $units = new class implements AgentViewConverger, FleetConvergeUnits, GatewayReleaseUnitConverger, RuntimeHibernatorConverger
        {
            public function converge(): void {}

            public function start(): bool
            {
                return true;
            }

            public function startLater(): bool
            {
                return true;
            }
        };
        foreach ([AgentViewConverger::class, FleetConvergeUnits::class, GatewayReleaseUnitConverger::class, RuntimeHibernatorConverger::class] as $unit) {
            $this->app->instance($unit, $units);
        }
        $this->app->forgetInstance(GatewayWebConverger::class);
        $this->gateway = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.2',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.2',
        ]);
        $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    });

    afterEach(function (): void {
        new Filesystem()->deleteDirectory($this->home);
    });

    it('installs and runs the Documents hook before the reload in Gateway web convergence', function (): void {
        app(GatewayWebConverger::class)->converge($this->gateway, 'gateway.orbit', '10.44.0.2');

        expect(start_hook_steps($this->runner->calls))->toBe(['install project-document-cleanup-start', 'run project-document-cleanup-start', 'reload']);
    });

    it('installs and runs the Documents hook before the reload in the release handoff', function (): void {
        $result = app(GatewayRuntimeHandoff::class)->serve();

        expect($result['fpm'])->toBe('reloaded')
            ->and(start_hook_steps($this->runner->calls))->toBe(['install project-document-cleanup-start', 'run project-document-cleanup-start', 'reload']);
    });
});
