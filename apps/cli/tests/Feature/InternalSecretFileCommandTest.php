<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal secret file command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before staging secret content', function (): void {
        [$exitCode, $output] = run_internal_secret_file_command([
            'action' => 'stage',
            '--json' => true,
        ], [
            'content_base64' => base64_encode('super-secret-token'),
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('stages secret content into a temporary file without echoing content', function (): void {
        [$exitCode, $output] = run_internal_secret_file_command([
            'action' => 'stage',
            '--operation-token' => secret_file_signed_operation_token(),
            '--json' => true,
        ], [
            'content_base64' => base64_encode('super-secret-token'),
        ]);

        $data = secret_file_success_data($output);
        $path = $data['path'] ?? null;

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->not
            ->toContain('super-secret-token')
            ->and($path)
            ->toBeString()
            ->and(str_starts_with(basename((string) $path), 'orbit-secret.'))
            ->toBeTrue()
            ->and(file_get_contents((string) $path))
            ->toBe('super-secret-token')
            ->and(substr(sprintf('%o', fileperms((string) $path) ?: 0), -4))
            ->toBe('0600');

        @unlink((string) $path);
    });

    it('removes only orbit temporary secret paths', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'orbit-secret.');

        if (! is_string($path)) {
            throw new RuntimeException('Could not create test secret file.');
        }

        file_put_contents($path, 'secret');

        [$exitCode, $output] = run_internal_secret_file_command([
            'action' => 'remove',
            '--operation-token' => secret_file_signed_operation_token(),
            '--json' => true,
        ], [
            'path' => $path,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(secret_file_success_data($output))
            ->toMatchArray([
                'path' => $path,
                'removed' => true,
            ])
            ->and(is_file($path))
            ->toBeFalse();
    });

    it('rejects non secret temporary paths', function (): void {
        [$exitCode, $output] = run_internal_secret_file_command([
            'action' => 'remove',
            '--operation-token' => secret_file_signed_operation_token(),
            '--json' => true,
        ], [
            'path' => '/tmp/not-orbit-secret',
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('"code":"validation_failed"')
            ->and($output)
            ->toContain('"message":"Secret file path is invalid."');
    });
});

function secret_file_signed_operation_token(
    string $id = 'secret-file',
    string $node = 'app-dev',
    string $command = 'internal:secret-file',
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
 * @param  array<string, mixed>  $payload
 * @return array{int, string}
 */
function run_internal_secret_file_command(array $parameters = [], array $payload = []): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, json_encode($payload, JSON_THROW_ON_ERROR));
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $commands = Artisan::all();
    /** @var SymfonyCommand|null $command */
    $command = $commands['internal:secret-file'] ?? null;

    if (! $command instanceof SymfonyCommand) {
        throw new RuntimeException('Internal secret file command is not registered.');
    }

    $output = new BufferedOutput;
    $exitCode = $command->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

/**
 * @return array<string, mixed>
 */
function secret_file_success_data(string $output): array
{
    /** @var mixed $payload */
    $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($payload)) {
        return [];
    }

    /** @var mixed $data */
    $data = data_get(target: $payload, key: 'success.data');

    if (! is_array($data)) {
        return [];
    }

    /** @var array<string, mixed> $data */
    return $data;
}
