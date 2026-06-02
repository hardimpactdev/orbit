<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Process\Process;

describe('internal executor verification command', function (): void {
    it('rejects a missing operation token', function (): void {
        configureOperationTokenGuard();

        [$exitCode, $output] = runInternalExecutorCommand($this);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('missing_token')
            ->and($output)->toContain('Operation token is required.');
    });

    it('returns missing_token failure envelope as JSON when --json is set and token is absent', function (): void {
        configureOperationTokenGuard();

        [$exitCode, $output] = runInternalExecutorCommand($this, [
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects an empty operation token', function (): void {
        configureOperationTokenGuard();

        [$exitCode, $output] = runInternalExecutorCommand($this, [
            '--operation-token' => '',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('missing_token')
            ->and($output)->toContain('Operation token is required.');
    });

    it('returns missing_token failure envelope as JSON when --json and --operation-token= are empty', function (): void {
        configureOperationTokenGuard();

        [$exitCode, $output] = runInternalExecutorCommand($this, [
            '--operation-token' => '',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects a malformed operation token without leaking parser details', function (): void {
        configureOperationTokenGuard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => false,
        ]));

        [$exitCode, $output] = runInternalExecutorCommand($this, [
            '--operation-token' => 'not-a-token',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('invalid_token')
            ->and($output)->toContain('Operation token is invalid.')
            ->and($output)->not->toContain('segment')
            ->and($output)->not->toContain('InvalidArgumentException');
    });

    it('returns invalid_token failure envelope as JSON when --json and token is malformed', function (): void {
        configureOperationTokenGuard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => false,
        ]));

        [$exitCode, $output] = runInternalExecutorCommand($this, [
            '--operation-token' => 'not-a-token',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'invalid_token',
                'Operation token is invalid.',
            ));
    });

    it('maps gateway rejection to invalid_token for a wrong-node token', function (): void {
        configureOperationTokenGuard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => false,
        ]));

        [$exitCode, $output] = runInternalExecutorCommand($this, [
            '--operation-token' => signedOperationToken(node: 'app-prod'),
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('invalid_token')
            ->and($output)->toContain('Operation token is invalid.');
    });

    it('returns invalid_token failure envelope as JSON when the gateway rejects the token', function (): void {
        configureOperationTokenGuard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => false,
        ]));

        [$exitCode, $output] = runInternalExecutorCommand($this, [
            '--operation-token' => signedOperationToken(node: 'app-prod'),
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'invalid_token',
                'Operation token is invalid.',
            ));
    });

    it('posts the token and expected command to the gateway verifier endpoint', function (): void {
        configureOperationTokenGuard();

        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));

        $token = signedOperationToken(command: 'internal:workspace-adapter');

        [$exitCode, $output] = runInternalExecutorCommand($this, [
            '--operation-token' => $token,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('operation_id: operation-verify')
            ->and($output)->toContain('node: app-dev')
            ->and($output)->toContain('command: internal:workspace-adapter');

        Http::assertSent(function (Request $request) use ($token): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/internal-executor/token/verify'
                && $request['operation_token'] === $token
                && $request['command'] === 'internal:executor:verify';
        });
    });

    it('accepts a valid operation token', function (): void {
        configureOperationTokenGuard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));

        [$exitCode, $output] = runInternalExecutorCommand($this, [
            '--operation-token' => signedOperationToken(id: 'operation-verify-1'),
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('operation_id: operation-verify-1')
            ->and($output)->toContain('node: app-dev')
            ->and($output)->toContain('command: internal:executor:verify');
    });

    it('outputs a success envelope as JSON for a valid operation token', function (): void {
        configureOperationTokenGuard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));

        [$exitCode, $output] = runInternalExecutorCommand($this, [
            '--operation-token' => signedOperationToken(id: 'operation-verify-json'),
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe(json_encode(
                JsonEnvelope::success([
                    'operation_id' => 'operation-verify-json',
                    'node' => 'app-dev',
                    'command' => 'internal:executor:verify',
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
    });

    it('hides the internal executor verification command from php orbit list', function (): void {
        $process = new Process([PHP_BINARY, 'orbit', 'list'], base_path());
        $process->run();

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->not->toContain('internal:executor:verify');
    });
});

function configureOperationTokenGuard(): void
{
    app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
}

function signedOperationToken(
    string $id = 'operation-verify',
    string $node = 'app-dev',
    string $command = 'internal:executor:verify',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return (new OperationTokenSigner)->sign(
        secret: 'gateway-secret',
        id: $id,
        node: $node,
        command: $command,
        issuedAt: $issuedAt,
        expiresAt: $expiresAt,
    )->toString();
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function runInternalExecutorCommand(object $test, array $parameters = []): array
{
    $test->mockConsoleOutput = false;
    app()->offsetUnset(OutputStyle::class);

    $exitCode = $test->artisan('internal:executor:verify', $parameters);

    return [$exitCode, trim(app(Kernel::class)->output())];
}
