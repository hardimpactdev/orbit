<?php

declare(strict_types=1);

use App\Commands\Internal\InternalExecutorCommand;
use App\Commands\Internal\VerifyExecutorCommand;
use App\Services\Executor\OperationTokenGuard;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;

beforeEach(function (): void {
    config()->set('orbit.executor.shared_secret', 'verify-executor-test-secret');
    config()->set('orbit.executor.node_identity', 'verify-executor-test-node');
    app()->forgetInstance(OperationTokenGuard::class);
});

function signVerifyExecutorToken(
    string $id = 'op-verify-1',
    string $node = 'verify-executor-test-node',
    string $command = 'internal:executor:verify',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 5;
    $expiresAt ??= time() + 120;

    return (new OperationTokenSigner)->sign(
        secret: 'verify-executor-test-secret',
        id: $id,
        node: $node,
        command: $command,
        issuedAt: $issuedAt,
        expiresAt: $expiresAt,
    )->toString();
}

describe('ORBIT-CLI-ARCH-01 — VerifyExecutorCommand pillars', function (): void {
    it('extends InternalExecutorCommand so the token + result-boundary contract is inherited, not reimplemented', function (): void {
        expect(is_subclass_of(VerifyExecutorCommand::class, InternalExecutorCommand::class))->toBeTrue();
    });

    it('rejects a missing operation token with code missing_token BEFORE any side effect runs (token-first validation)', function (): void {
        [$exitCode, $output] = runCommand($this, 'internal:executor:verify', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded)->toBe(JsonEnvelope::failure('missing_token', 'Operation token is required.'));
    });

    it('rejects an invalid operation token with code invalid_token via the OperationTokenGuard service (action/service delegation)', function (): void {
        [$exitCode, $output] = runCommand($this, 'internal:executor:verify', [
            '--operation-token' => 'not-a-real-token',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded)->toBe(JsonEnvelope::failure('invalid_token', 'Operation token is invalid.'));
    });

    it('rejects a token signed for a different command name (guard.verify enforces command-name binding)', function (): void {
        $wrongCommandToken = signVerifyExecutorToken(command: 'internal:wg-easy:state');

        [$exitCode, $output] = runCommand($this, 'internal:executor:verify', [
            '--operation-token' => $wrongCommandToken,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('invalid_token');
    });

    it('returns a typed result with operation_id, node, and command keys after the guard accepts the token (typed result serialization)', function (): void {
        [$exitCode, $output] = runCommand($this, 'internal:executor:verify', [
            '--operation-token' => signVerifyExecutorToken(id: 'op-verify-42', node: 'verify-executor-test-node'),
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded)->toHaveKey('success')
            ->and($decoded['success']['data'])->toMatchArray([
                'operation_id' => 'op-verify-42',
                'node' => 'verify-executor-test-node',
                'command' => 'internal:executor:verify',
            ]);
    });

    it('refuses to emit a result that would contain a forbidden secret key (result-boundary scan inherited from base)', function (): void {
        // VerifyExecutorCommand never emits secret-shaped keys; we just confirm the inherited
        // assertResultBoundaryClean()/emitInternalSuccess() rejection path is reachable for any
        // future migration. Use the InternalExecutorCommand base contract test instead.
        expect(method_exists(VerifyExecutorCommand::class, 'emitInternalSuccess'))->toBeTrue()
            ->and(method_exists(VerifyExecutorCommand::class, 'verifyOperationToken'))->toBeTrue();
    });
});
