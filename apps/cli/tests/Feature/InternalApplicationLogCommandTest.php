<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal:application-log', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        config()->set('orbit.operation_token_secret', application_log_operation_secret());
    });

    it('is registered', function (): void {
        expect(array_key_exists('internal:application-log', Artisan::all()))->toBeTrue();
    });

    it('returns empty success when the fixed log file is missing', function (): void {
        $root = sys_get_temp_dir().'/orbit-app-log-missing-'.uniqid();
        mkdir($root.'/storage/logs', 0777, true);
        $absolute = $root.'/storage/logs/laravel.log';

        try {
            [$exitCode, $output] = run_internal_application_log_command(
                [
                    '--operation-token' => application_log_signed_operation_token(),
                    '--json' => true,
                ],
                json_encode([
                    'absolute_path' => $absolute,
                    'authorized_root' => $root,
                    'lines' => 100,
                    'follow' => false,
                ], JSON_THROW_ON_ERROR),
            );

            $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(0)
                ->and($payload['success']['data']['file_exists'])
                ->toBeFalse()
                ->and($payload['success']['data']['lines'])
                ->toBe([])
                ->and($payload['success']['data']['path'])
                ->toBe('storage/logs/laravel.log');
        } finally {
            if (is_dir($root.'/storage/logs')) {
                rmdir($root.'/storage/logs');
            }
            if (is_dir($root.'/storage')) {
                rmdir($root.'/storage');
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    });

    it('rejects path escape outside authorized root', function (): void {
        $root = sys_get_temp_dir().'/orbit-app-log-safe-'.uniqid();
        mkdir($root, 0777, true);

        try {
            [$exitCode, $output] = run_internal_application_log_command(
                [
                    '--operation-token' => application_log_signed_operation_token(),
                    '--json' => true,
                ],
                json_encode([
                    'absolute_path' => '/etc/passwd',
                    'authorized_root' => $root,
                    'lines' => 10,
                    'follow' => false,
                ], JSON_THROW_ON_ERROR),
            );

            $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(1)
                ->and($payload['error']['code'] ?? null)
                ->not->toBeNull();
        } finally {
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    });

    it('accepts PHP_INT_MAX lines and rejects overflow line counts', function (): void {
        $root = sys_get_temp_dir().'/orbit-app-log-lines-'.uniqid();
        mkdir($root.'/storage/logs', 0777, true);
        $absolute = $root.'/storage/logs/laravel.log';

        try {
            [$okExit, $okOutput] = run_internal_application_log_command(
                [
                    '--operation-token' => application_log_signed_operation_token(),
                    '--json' => true,
                ],
                json_encode([
                    'absolute_path' => $absolute,
                    'authorized_root' => $root,
                    'lines' => PHP_INT_MAX,
                    'follow' => false,
                ], JSON_THROW_ON_ERROR),
            );

            $okPayload = json_decode($okOutput, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($okExit)
                ->toBe(0)
                ->and($okPayload['success']['data']['file_exists'] ?? null)
                ->toBeFalse();

            [$badExit, $badOutput] = run_internal_application_log_command(
                [
                    '--operation-token' => application_log_signed_operation_token(),
                    '--json' => true,
                ],
                json_encode([
                    'absolute_path' => $absolute,
                    'authorized_root' => $root,
                    'lines' => '999999999999999999999999999',
                    'follow' => false,
                ], JSON_THROW_ON_ERROR),
            );

            $badPayload = json_decode($badOutput, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($badExit)
                ->toBe(1)
                ->and($badPayload['error']['meta']['field'] ?? null)
                ->toBe('lines');
        } finally {
            if (is_dir($root.'/storage/logs')) {
                rmdir($root.'/storage/logs');
            }
            if (is_dir($root.'/storage')) {
                rmdir($root.'/storage');
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    });
});

describe('internal:application-log unreadable', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        config()->set('orbit.operation_token_secret', application_log_operation_secret());
    });

    it('returns application_log.unreadable when the existing log file is unreadable', function (): void {
        $root = sys_get_temp_dir().'/orbit-app-log-unreadable-'.uniqid();
        mkdir($root.'/storage/logs', recursive: true);
        $absolute = $root.'/storage/logs/laravel.log';
        file_put_contents($absolute, "[history] unreadable\n");
        $originalMode = fileperms($absolute);

        try {
            chmod($absolute, 0o000);

            [$exitCode, $output] = run_internal_application_log_command(
                [
                    '--operation-token' => application_log_signed_operation_token(),
                    '--json' => true,
                ],
                json_encode([
                    'absolute_path' => $absolute,
                    'authorized_root' => $root,
                    'lines' => 100,
                    'follow' => false,
                ], JSON_THROW_ON_ERROR),
            );

            $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(1)
                ->and($payload['error']['code'] ?? null)
                ->toBe('application_log.unreadable');
        } finally {
            restore_application_log_fixture($root, $absolute, $originalMode);
        }
    });
});

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_application_log_command(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    /** @var mixed $command */
    $command = Artisan::all()['internal:application-log'] ?? null;

    if (! $command instanceof SymfonyCommand) {
        throw new RuntimeException('internal:application-log not registered.');
    }

    $exitCode = $command->run($input, $output);

    return [$exitCode, $output->fetch()];
}

function application_log_signed_operation_token(): string
{
    return new OperationTokenSigner()
        ->sign(
            secret: application_log_operation_secret(),
            id: 'application-log',
            node: 'app-dev',
            command: 'internal:application-log',
            issuedAt: time() - 10,
            expiresAt: time() + 120,
        )
        ->toString();
}

function application_log_operation_secret(): string
{
    return 'application-log-test-secret';
}

function restore_application_log_fixture(string $root, string $absolute, int|false $originalMode): void
{
    if (is_file($absolute)) {
        chmod($absolute, is_int($originalMode) ? $originalMode & 0o777 : 0o644);
        unlink($absolute);
    }

    if (is_dir($root.'/storage/logs')) {
        rmdir($root.'/storage/logs');
    }

    if (is_dir($root.'/storage')) {
        rmdir($root.'/storage');
    }

    if (is_dir($root)) {
        rmdir($root);
    }
}
