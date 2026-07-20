<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal env-file command', function (): void {
    beforeEach(function (): void {
        configureEnvFileOperationTokenGuard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = runInternalEnvFileCommand(['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects invalid json payloads after token validation', function (): void {
        [$exitCode, $output] = runInternalEnvFileCommand([
            '--operation-token' => envFileSignedOperationToken(),
            '--json' => true,
        ], 'not-json');

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Env file payload is invalid.',
            ));
    });

    it('rejects non-env paths outside managed roots', function (): void {
        [$exitCode, $output] = runInternalEnvFileCommand(
            [
                '--operation-token' => envFileSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'read',
                'path' => '/etc/passwd',
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed')
            ->and($payload['error']['message'] ?? null)
            ->toBe('Env file path is invalid.');
    });

    it('accepts Orbit-managed production app env paths', function (): void {
        [$exitCode, $output] = runInternalEnvFileCommand(
            [
                '--operation-token' => envFileSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'read',
                'path' => '/home/mealou-production/app/.env',
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('env_file.not_found');
    });

    it('keeps production env access bounded to the exact app root', function (string $path): void {
        [$exitCode, $output] = runInternalEnvFileCommand(
            [
                '--operation-token' => envFileSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'read',
                'path' => $path,
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed');
    })->with([
        '/home/mealou-production/.env',
        '/home/mealou-production/app/config/.env',
        '/home/mealou-production/app/../.env',
    ]);

    it('accepts Orbit-managed development app env paths', function (string $path): void {
        [$exitCode, $output] = runInternalEnvFileCommand(
            [
                '--operation-token' => envFileSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'read',
                'path' => $path,
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('env_file.not_found');
    })->with([
        'linux app-dev' => '/home/orbit-test-user/apps/mealou-env-test/.env',
        'macOS app-dev' => '/Users/orbit-test-user/apps/mealou-env-test/.env',
    ]);

    it('keeps development env access bounded to the exact app root', function (string $path): void {
        [$exitCode, $output] = runInternalEnvFileCommand(
            [
                '--operation-token' => envFileSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'read',
                'path' => $path,
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed');
    })->with([
        '/home/nckrtl/mealou/.env',
        '/home/nckrtl/apps/mealou/config/.env',
        '/home/nckrtl/apps/mealou/../.env',
        '/Users/nckrtl/mealou/.env',
        '/Users/nckrtl/apps/mealou/config/.env',
        '/Users/nckrtl/apps/mealou/../.env',
    ]);
});

function configureEnvFileOperationTokenGuard(): void
{
    app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
}

function envFileSignedOperationToken(
    string $id = 'env-file',
    string $node = 'app-dev',
    string $command = 'internal:env-file',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: 'gateway-secret',
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function runInternalEnvFileCommand(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $exitCode = Artisan::all()['internal:env-file']->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}
