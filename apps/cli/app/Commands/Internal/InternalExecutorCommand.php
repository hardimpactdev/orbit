<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Commands\Concerns\EmitsCanonicalEnvelopes;
use App\Exceptions\OperationTokenGuardException;
use App\Services\Executor\OperationTokenGuard;
use LaravelZero\Framework\Commands\Command;

/**
 * Abstract base for hidden internal executor commands.
 *
 * Every concrete subclass must:
 *   1. Call verifyOperationToken($commandName) at the start of handle().
 *   2. Call emitInternalSuccess($data) instead of renderSuccess() when returning results.
 */
abstract class InternalExecutorCommand extends Command
{
    use EmitsCanonicalEnvelopes;

    /**
     * Forbidden result keys (case-insensitive substring match against array key names).
     * This catches exact matches plus pattern-equivalent names (e.g. api_token via _token,
     * apiKey via api_key, etc.) as required by the internal executor result-boundary contract.
     *
     * @var list<string>
     */
    private const array ForbiddenKeys = [
        'operation_token',
        'executor_secret',
        'password',
        'bearer',
        'secret',
        '_token',
        'api_key',
    ];

    /**
     * PEM block pattern that must never appear in result values.
     */
    private const string PemBlockPattern = '/-----BEGIN [A-Z ]+-----[\s\S]*?-----END [A-Z ]+-----/';

    /**
     * Verify the operation token option against the expected command name.
     *
     * Returns true when the token is valid. Calls renderFailure() and returns false
     * when validation fails — callers must propagate the failure exit code themselves.
     */
    protected function verifyOperationToken(string $expectedCommand): bool
    {
        $compactToken = $this->option('operation-token');

        if (! is_string($compactToken) || trim($compactToken) === '') {
            $this->renderFailure('missing_token', 'Operation token is required.', []);

            return false;
        }

        try {
            /** @var OperationTokenGuard $guard */
            $guard = app(OperationTokenGuard::class);
            $guard->verify($compactToken, $expectedCommand);
        } catch (OperationTokenGuardException) {
            $this->renderFailure('invalid_token', 'Operation token is invalid.', []);

            return false;
        }

        return true;
    }

    /**
     * Scan a result array recursively for forbidden keys and PEM block values.
     *
     * Returns null when the result is clean. Returns the renderFailure() exit code
     * (self::FAILURE) when a forbidden pattern is found — the caller must NOT write
     * the result in that case.
     *
     * @param  array<string, mixed>  $result
     */
    protected function assertResultBoundaryClean(array $result): ?int
    {
        $match = $this->findForbiddenField($result);

        if ($match !== null) {
            return $this->renderFailure(
                'result_contains_secret',
                'Internal command result contains a redacted secret pattern.',
                ['field' => $match],
            );
        }

        return null;
    }

    /**
     * Emit a successful internal result after scanning for forbidden fields.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    protected function emitInternalSuccess(array $data, array $meta = []): int
    {
        $redactionResult = $this->assertResultBoundaryClean($data);

        if ($redactionResult !== null) {
            return $redactionResult;
        }

        return $this->renderSuccess($data, $meta);
    }

    /**
     * Recursively search for a forbidden key or PEM value in an array.
     *
     * Returns the matched key path string on a hit, null if clean.
     *
     * @param  array<string, mixed>  $data
     */
    private function findForbiddenField(array $data, string $prefix = ''): ?string
    {
        foreach ($data as $key => $value) {
            $keyPath = $prefix !== '' ? "{$prefix}.{$key}" : (string) $key;

            if ($this->isForbiddenKey((string) $key)) {
                return $keyPath;
            }

            if (is_string($value) && $this->containsPemBlock($value)) {
                return $keyPath;
            }

            if (is_array($value)) {
                $nested = $this->findForbiddenField($value, $keyPath);

                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function isForbiddenKey(string $key): bool
    {
        $lower = strtolower($key);
        $normalized = $this->normalizeKeyForSecretScan($key);

        foreach (self::ForbiddenKeys as $forbidden) {
            $forbidden = strtolower($forbidden);

            if (str_contains($lower, $forbidden) || str_contains($normalized, $forbidden)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeKeyForSecretScan(string $key): string
    {
        $key = preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key;
        $key = preg_replace('/[^A-Za-z0-9]+/', '_', $key) ?? $key;

        return strtolower($key);
    }

    private function containsPemBlock(string $value): bool
    {
        return preg_match(self::PemBlockPattern, $value) === 1;
    }
}
