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
function run_internal_process_launchd_service_command(array $parameters = []): array
{
    $output = new BufferedOutput;
    /** @var mixed $command */
    $command = Artisan::all()['internal:process-launchd-service'] ?? null;

    if (! $command instanceof SymfonyCommand) {
        // Force discovery failure visible to test when not registered
        $exitCode = Artisan::call('list', ['--format' => 'json']);
        throw new RuntimeException('internal:process-launchd-service not registered; list exit='.$exitCode);
    }

    $exitCode = $command->run(new ArrayInput($parameters), $output);

    return [$exitCode, $output->fetch()];
}

function install_launchd_fake_bin(): string
{
    return install_launchd_fake_bin_with_enable_exit_code(enableExitCode: 0);
}

function install_launchd_fake_bin_with_enable_failure(): string
{
    return install_launchd_fake_bin_with_enable_exit_code(enableExitCode: 42);
}

function install_launchd_fake_bin_with_enable_exit_code(int $enableExitCode): string
{
    $dir = sys_get_temp_dir().'/orbit-launchd-fake-bin-'.bin2hex(random_bytes(8));
    mkdir($dir, permissions: 0o755, recursive: true);

    app()->instance(LocalLaunchdServiceAction::class, new LocalLaunchdServiceAction(osFamily: 'Darwin'));

    $enableExit = "exit({$enableExitCode});";

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
            echo "launchctl print output\n";
            exit(0);
        }
        if (\$cmd === 'enable') {
            fwrite(STDERR, "enable failed\n");
            {$enableExit}
        }
        file_put_contents(__DIR__.'/calls.log', implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);
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
