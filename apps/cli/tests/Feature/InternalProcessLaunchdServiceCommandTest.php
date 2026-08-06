<?php

declare(strict_types=1);

use App\Services\Processes\LocalLaunchdServiceAction;
use App\Services\Processes\LocalLaunchdServiceFailure;
use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    $path = getenv('PATH');
    launchd_service_original_path(is_string($path) ? $path : '');
});

afterEach(function (): void {
    putenv('PATH='.launchd_service_original_path());
});

/**
 * @mago-expect lint:halstead
 */
describe('internal process launchd service command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        app()->instance(LocalLaunchdServiceAction::class, new LocalLaunchdServiceAction(osFamily: 'Darwin'));
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before any launchctl execution', function (): void {
        $exitCode = Artisan::call('internal:process-launchd-service', [
            'action' => 'start',
            'label' => 'dev.hardimpact.orbit.test-unit',
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects invalid launchd labels', function (): void {
        $exitCode = Artisan::call('internal:process-launchd-service', [
            'action' => 'start',
            'label' => 'invalid label with spaces',
            '--operation-token' => launchd_service_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Launchd label is invalid.',
                ['field' => 'label'],
            ));
    });

    it('requires valid plist path under LaunchAgents for remove', function (): void {
        $exitCode = Artisan::call('internal:process-launchd-service', [
            'action' => 'remove',
            'label' => 'dev.hardimpact.orbit.test-unit',
            'plist-path' => '/tmp/wrong.plist',
            '--operation-token' => launchd_service_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Launchd plist path is invalid.',
                ['field' => 'plist-path'],
            ));
    });

    it('requires plist content for apply action', function (): void {
        $exitCode = Artisan::call('internal:process-launchd-service', [
            'action' => 'apply',
            'label' => 'dev.hardimpact.orbit.test-unit',
            '--operation-token' => launchd_service_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Launchd plist content is invalid.',
                ['field' => 'content'],
            ));
    });

    it('probes launchd service state through fixed argv launchctl print', function (): void {
        install_launchd_fake_bin();

        [$exitCode, $output] = run_internal_process_launchd_service_command([
            'action' => 'probe',
            'label' => 'dev.hardimpact.orbit.test-unit',
            '--operation-token' => launchd_service_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toHaveKey('success');
    });

    it('treats launchd is-active as running only when print reports state running and a pid', function (): void {
        install_launchd_fake_bin_with_print_output("state = running\npid = 4242\n");

        $result = app(LocalLaunchdServiceAction::class)->run(
            action: 'is-active',
            label: 'dev.hardimpact.orbit.test-unit',
            plistPath: null,
        );

        expect($result)
            ->toBe([
                'action' => 'is-active',
                'label' => 'dev.hardimpact.orbit.test-unit',
                'changed' => false,
            ]);
    });

    it('rejects launchd is-active when print only proves the job is loaded', function (): void {
        install_launchd_fake_bin_with_print_output("state = not running\npid = 0\n");

        expect(fn (): array => app(LocalLaunchdServiceAction::class)->run(
            action: 'is-active',
            label: 'dev.hardimpact.orbit.test-unit',
            plistPath: null,
        ))
            ->toThrow(LocalLaunchdServiceFailure::class);
    });

    it('rejects launchd actions on non macOS hosts before writing LaunchAgents', function (): void {
        $action = new LocalLaunchdServiceAction(osFamily: 'Linux');

        expect(fn (): array => $action->run(
            action: 'probe',
            label: 'dev.hardimpact.orbit.test-unit',
            plistPath: null,
        ))
            ->toThrow(LocalLaunchdServiceFailure::class, 'Launchd process service actions require macOS.');
    });

    it('fails start when launchctl enable fails', function (): void {
        install_launchd_fake_bin_with_enable_failure();

        [$exitCode, $output] = run_internal_process_launchd_service_command([
            'action' => 'start',
            'label' => 'dev.hardimpact.orbit.test-unit',
            '--operation-token' => launchd_service_signed_operation_token(),
            '--json' => true,
        ]);

        /** @var array{error: array{code: string, meta: array<string, mixed>}} $payload */
        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'])
            ->toBe('launchd_service.start_failed')
            ->and($payload['error']['meta'])
            ->toMatchArray([
                'action' => 'start',
                'label' => 'dev.hardimpact.orbit.test-unit',
                'exit_code' => 42,
                'stderr' => 'enable failed',
            ]);
    });

    it('confirms an already running unit without restarting it', function (): void {
        $bin = install_launchd_fake_bin();

        [$exitCode, $output] = run_internal_process_launchd_service_command([
            'action' => 'start',
            'label' => 'dev.hardimpact.orbit.test-unit',
            '--operation-token' => launchd_service_signed_operation_token(),
            '--json' => true,
        ]);

        /** @var array{success: array{data: array{changed: bool}}} $payload */
        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $calls = file_get_contents("{$bin}/calls.log");
        $target = 'gui/'.getmyuid().'/dev.hardimpact.orbit.test-unit';

        expect($exitCode)
            ->toBe(0, $output)
            ->and($payload['success']['data']['changed'])
            ->toBeFalse()
            ->and($calls)
            ->toBe("enable {$target}\n");
    });

    it('waits across the launchd throttle window before declaring start failure', function (): void {
        install_launchd_fake_bin_stateful(
            bootstrapFirstCallExitCode: 37,
            kickstartExitCode: 5,
            printRunningAfterPrintCalls: 3,
        );

        [$exitCode, $output] = run_internal_process_launchd_service_command([
            'action' => 'start',
            'label' => 'dev.hardimpact.orbit.test-unit',
            '--operation-token' => launchd_service_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0, $output);
    });

    it('starts a stopped unit by rebooting the on-disk definition and kickstarting without a kill', function (): void {
        $bin = install_launchd_fake_bin_stateful(
            bootstrapFirstCallExitCode: 0,
            kickstartExitCode: 0,
            printRunningAfterPrintCalls: 2,
        );

        [$exitCode, $output] = run_internal_process_launchd_service_command([
            'action' => 'start',
            'label' => 'dev.hardimpact.orbit.test-unit',
            '--operation-token' => launchd_service_signed_operation_token(),
            '--json' => true,
        ]);

        $calls = (string) file_get_contents("{$bin}/calls.log");
        $target = 'gui/'.getmyuid().'/dev.hardimpact.orbit.test-unit';
        $plist = getenv('HOME').'/Library/LaunchAgents/dev.hardimpact.orbit.test-unit.plist';

        expect($exitCode)
            ->toBe(0, $output)
            ->and($calls)
            ->toBe(
                "enable {$target}\n"
                ."bootout {$target}\n"
                .'bootstrap gui/'
                .getmyuid()
                ." {$plist}\n"
                ."kickstart {$target}\n",
            );
    });

    it('resets a stuck unit through bootout so start does not accumulate spawn penalties', function (): void {
        $bin = install_launchd_fake_bin_stateful(
            bootstrapFirstCallExitCode: 37,
            kickstartExitCode: 0,
            printRunningAfterBootout: true,
        );

        [$exitCode, $output] = run_internal_process_launchd_service_command([
            'action' => 'start',
            'label' => 'dev.hardimpact.orbit.test-unit',
            '--operation-token' => launchd_service_signed_operation_token(),
            '--json' => true,
        ]);

        $calls = (string) file_get_contents("{$bin}/calls.log");
        $target = 'gui/'.getmyuid().'/dev.hardimpact.orbit.test-unit';

        expect($exitCode)
            ->toBe(0, $output)
            ->and($calls)
            ->toContain("bootout {$target}");
    });

    it('fails start only after polling and recovery both observe a unit that never runs', function (): void {
        $bin = install_launchd_fake_bin_stateful(
            bootstrapFirstCallExitCode: 37,
            kickstartExitCode: 0,
            printRunningAfterPrintCalls: null,
        );

        [$exitCode, $output] = run_internal_process_launchd_service_command([
            'action' => 'start',
            'label' => 'dev.hardimpact.orbit.test-unit',
            '--operation-token' => launchd_service_signed_operation_token(),
            '--json' => true,
        ]);

        /** @var array{error: array{code: string}} $payload */
        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $calls = (string) file_get_contents("{$bin}/calls.log");
        $target = 'gui/'.getmyuid().'/dev.hardimpact.orbit.test-unit';

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'])
            ->toBe('launchd_service.start_failed')
            ->and($calls)
            ->toContain("bootout {$target}");
    });

    it('applies development launch agents disabled and disables them again when stopped', function (): void {
        $bin = install_launchd_fake_bin();
        $home = sys_get_temp_dir().'/orbit-launchd-home-'.bin2hex(random_bytes(8));
        mkdir($home);
        $originalHome = getenv('HOME');
        putenv("HOME={$home}");

        try {
            [$applyExitCode, $applyOutput] = run_internal_process_launchd_service_command(
                [
                    'action' => 'apply',
                    'label' => 'dev.hardimpact.orbit.test-unit',
                    '--operation-token' => launchd_service_signed_operation_token(id: 'launchd-apply'),
                    '--json' => true,
                ],
                json_encode([
                    'content' => '<plist version="1.0"></plist>',
                    'enabled' => false,
                ], JSON_THROW_ON_ERROR),
            );
            [$stopExitCode] = run_internal_process_launchd_service_command([
                'action' => 'stop',
                'label' => 'dev.hardimpact.orbit.test-unit',
                '--operation-token' => launchd_service_signed_operation_token(id: 'launchd-stop'),
                '--json' => true,
            ]);

            $calls = file_get_contents("{$bin}/calls.log");
            $target = 'gui/'.getmyuid().'/dev.hardimpact.orbit.test-unit';

            expect($applyExitCode)
                ->toBe(0, $applyOutput)
                ->and($stopExitCode)
                ->toBe(0)
                ->and($calls)
                ->toContain("disable {$target}")
                ->toContain("bootout {$target}");
        } finally {
            $originalHome === false ? putenv('HOME') : putenv("HOME={$originalHome}");
            Illuminate\Support\Facades\File::deleteDirectory($home);
        }
    });
});

it('accepts launchd provider for LocalRuntimeBackendProbe using launchctl help', function (): void {
    install_launchd_fake_bin();

    $probe = app(\App\Services\RuntimeBackend\LocalRuntimeBackendProbe::class);
    $result = $probe->check('launchd');

    expect($result)
        ->toHaveKey('provider', 'launchd')
        ->and($result['available'])
        ->toBeTrue()
        ->and($result['output'])
        ->toBe('launchd provider ready');
});

function launchd_service_signed_operation_token(
    string $id = 'process-launchd',
    string $node = 'app-dev',
    string $command = 'internal:process-launchd-service',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: launchd_service_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function launchd_service_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_process_launchd_service_command(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);
    $input = new ArrayInput($parameters);
    $input->setStream($stream);
    $output = new BufferedOutput;
    /** @var mixed $command */
    $command = Artisan::all()['internal:process-launchd-service'] ?? null;

    if (! $command instanceof SymfonyCommand) {
        // Force discovery failure visible to test when not registered
        $exitCode = Artisan::call('list', ['--format' => 'json']);
        throw new RuntimeException('internal:process-launchd-service not registered; list exit='.$exitCode);
    }

    $exitCode = $command->run($input, $output);

    return [$exitCode, $output->fetch()];
}

function install_launchd_fake_bin(): string
{
    return install_launchd_fake_bin_with_exit_codes(enableExitCode: 0, bootstrapExitCode: 0);
}

function install_launchd_fake_bin_with_enable_failure(): string
{
    return install_launchd_fake_bin_with_exit_codes(enableExitCode: 42, bootstrapExitCode: 0);
}

function install_launchd_fake_bin_with_print_output(string $printOutput): string
{
    return install_launchd_fake_bin_with_exit_codes(
        enableExitCode: 0,
        bootstrapExitCode: 0,
        printOutput: $printOutput,
    );
}

function install_launchd_fake_bin_with_exit_codes(
    int $enableExitCode,
    int $bootstrapExitCode,
    string $printOutput = "state = running\npid = 4242\n",
): string {
    $dir = sys_get_temp_dir().'/orbit-launchd-fake-bin-'.bin2hex(random_bytes(8));
    mkdir($dir, permissions: 0o755, recursive: true);

    app()->instance(LocalLaunchdServiceAction::class, new LocalLaunchdServiceAction(
        osFamily: 'Darwin',
        startReadinessTimeoutSeconds: 1.0,
        startReadinessPollMicroseconds: 10_000,
    ));

    $enableExit = "exit({$enableExitCode});";
    $bootstrapExit = "exit({$bootstrapExitCode});";
    $printLiteral = var_export($printOutput, true);

    file_put_contents("{$dir}/launchctl", <<<PHP
        #!/usr/bin/env php
        <?php
        \$argv = \$argv ?? [];
        \$cmd = \$argv[1] ?? '';
        if (\$cmd === 'help') {
            echo "launchctl help\n";
            exit(0);
        }
        if (\$cmd === 'print') {
            echo {$printLiteral};
            exit(0);
        }
        file_put_contents(__DIR__.'/calls.log', implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);
        if (\$cmd === 'enable') {
            fwrite(STDERR, "enable failed\n");
            {$enableExit}
        }
        if (\$cmd === 'bootstrap' && {$bootstrapExitCode} !== 0) {
            fwrite(STDERR, "service already loaded\n");
            {$bootstrapExit}
        }
        exit(0);
        PHP);
    chmod("{$dir}/launchctl", permissions: 0o755);

    $path = getenv('PATH');
    $path = is_string($path) ? $path : '';
    putenv("PATH={$dir}:{$path}");

    return $dir;
}

/**
 * Stateful launchctl fake for start-readiness scenarios: bootstrap can differ
 * between its first and later calls, kickstart can fail like a throttled
 * spawn, and print reports state=running either after N print polls, after a
 * bootout happened, or never (null).
 */
function install_launchd_fake_bin_stateful(
    int $bootstrapFirstCallExitCode,
    int $kickstartExitCode,
    ?int $printRunningAfterPrintCalls = null,
    bool $printRunningAfterBootout = false,
): string {
    $dir = sys_get_temp_dir().'/orbit-launchd-fake-bin-'.bin2hex(random_bytes(8));
    mkdir($dir, permissions: 0o755, recursive: true);

    app()->instance(LocalLaunchdServiceAction::class, new LocalLaunchdServiceAction(
        osFamily: 'Darwin',
        startReadinessTimeoutSeconds: 1.0,
        startReadinessPollMicroseconds: 10_000,
    ));

    $printThreshold = $printRunningAfterPrintCalls === null ? 'null' : (string) $printRunningAfterPrintCalls;
    $afterBootout = $printRunningAfterBootout ? 'true' : 'false';

    file_put_contents("{$dir}/launchctl", <<<PHP
        #!/usr/bin/env php
        <?php
        \$cmd = \$argv[1] ?? '';
        \$dir = __DIR__;
        if (\$cmd === 'help') {
            echo "launchctl help\\n";
            exit(0);
        }
        if (\$cmd === 'print') {
            \$count = ((int) @file_get_contents(\$dir.'/print-count')) + 1;
            file_put_contents(\$dir.'/print-count', (string) \$count);
            \$calls = (string) @file_get_contents(\$dir.'/calls.log');
            \$threshold = {$printThreshold};
            \$running = (\$threshold !== null && \$count >= \$threshold)
                || ({$afterBootout} && str_contains(\$calls, 'bootout'));
            echo \$running ? "state = running\\npid = 4242\\n" : "state = not running\\npid = 0\\n";
            exit(0);
        }
        file_put_contents(\$dir.'/calls.log', implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);
        if (\$cmd === 'bootstrap') {
            \$count = ((int) @file_get_contents(\$dir.'/bootstrap-count')) + 1;
            file_put_contents(\$dir.'/bootstrap-count', (string) \$count);
            if (\$count === 1 && {$bootstrapFirstCallExitCode} !== 0) {
                fwrite(STDERR, "service already loaded\\n");
                exit({$bootstrapFirstCallExitCode});
            }
            exit(0);
        }
        if (\$cmd === 'kickstart' && {$kickstartExitCode} !== 0) {
            fwrite(STDERR, "kickstart failed: throttled\\n");
            exit({$kickstartExitCode});
        }
        exit(0);
        PHP);
    chmod("{$dir}/launchctl", permissions: 0o755);

    $path = getenv('PATH');
    $path = is_string($path) ? $path : '';
    putenv("PATH={$dir}:{$path}");

    return $dir;
}

function launchd_service_original_path(?string $path = null): string
{
    static $originalPath = '';

    if ($path !== null) {
        $originalPath = $path;
    }

    return $originalPath;
}
