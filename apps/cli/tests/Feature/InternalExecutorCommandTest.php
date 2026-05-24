<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationToken;
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

    it('rejects an empty operation token', function (): void {
        configureOperationTokenGuard();

        [$exitCode, $output] = runInternalExecutorCommand($this, [
            '--operation-token' => '',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('missing_token')
            ->and($output)->toContain('Operation token is required.');
    });

    it('rejects a malformed operation token without leaking parser details', function (): void {
        configureOperationTokenGuard();

        [$exitCode, $output] = runInternalExecutorCommand($this, [
            '--operation-token' => 'not-a-token',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('invalid_token')
            ->and($output)->toContain('Operation token is invalid.')
            ->and($output)->not->toContain('segment')
            ->and($output)->not->toContain('InvalidArgumentException');
    });

    it('rejects a token signed for the wrong node', function (): void {
        configureOperationTokenGuard();

        [$exitCode, $output] = runInternalExecutorCommand($this, [
            '--operation-token' => signedOperationToken(node: 'app-prod'),
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('invalid_token')
            ->and($output)->toContain('Operation token is invalid.');
    });

    it('rejects a token signed for the wrong command', function (): void {
        configureOperationTokenGuard();

        [$exitCode, $output] = runInternalExecutorCommand($this, [
            '--operation-token' => signedOperationToken(command: 'internal:workspace-adapter'),
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('invalid_token')
            ->and($output)->toContain('Operation token is invalid.');
    });

    it('rejects an expired operation token', function (): void {
        configureOperationTokenGuard();

        [$exitCode, $output] = runInternalExecutorCommand($this, [
            '--operation-token' => signedOperationToken(issuedAt: time() - 240, expiresAt: time() - 120),
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('invalid_token')
            ->and($output)->toContain('Operation token is invalid.');
    });

    it('rejects a tampered operation token', function (): void {
        configureOperationTokenGuard();

        $token = OperationToken::parse(signedOperationToken());
        $tampered = new OperationToken(
            id: "{$token->id}-tampered",
            node: $token->node,
            command: $token->command,
            issued_at: $token->issued_at,
            expires_at: $token->expires_at,
            signature: $token->signature,
        );

        [$exitCode, $output] = runInternalExecutorCommand($this, [
            '--operation-token' => $tampered->toString(),
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('invalid_token')
            ->and($output)->toContain('Operation token is invalid.');
    });

    it('accepts a valid operation token', function (): void {
        configureOperationTokenGuard();

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
    config()->set('orbit.executor.shared_secret', 'gateway-secret');
    config()->set('orbit.executor.node_identity', 'app-dev');

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
